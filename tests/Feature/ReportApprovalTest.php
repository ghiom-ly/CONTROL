<?php

namespace Tests\Feature;

use App\Enums\FollowupResult;
use App\Models\DailyReport;
use App\Models\Issue;
use App\Models\Permission;
use App\Models\ReportStore;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\ShipmentFollowup;
use App\Models\Store;
use App\Models\StoreDailyMetric;
use App\Models\User;
use App\Notifications\ReportReviewedNotification;
use App\Services\DashboardService;
use App\Support\Period;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ReportApprovalTest extends TestCase
{
    private User $emp;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->emp = $this->makeUser();
        $this->admin = $this->makeUser(Role::ADMIN);
    }

    /** تقرير قيد المراجعة: [store_id => [count, [code => result]]] */
    private function pending(array $stores, ?User $owner = null, array $attrs = []): DailyReport
    {
        $owner ??= $this->emp;
        $r = DailyReport::factory()->pendingReview()->for($owner)->create($attrs);
        foreach ($stores as $storeId => [$count, $codes]) {
            $rs = ReportStore::create(['daily_report_id' => $r->id, 'store_id' => $storeId, 'shipments_count' => $count]);
            foreach ($codes as $code => $result) {
                ShipmentFollowup::create(['daily_report_id' => $r->id, 'report_store_id' => $rs->id, 'store_id' => $storeId,
                    'shipment_code' => $code, 'user_id' => $owner->id, 'followup_at' => now(), 'result' => $result]);
            }
        }

        return $r;
    }

    private function approve(DailyReport $r, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->post("/reports/{$r->id}/approve");
    }

    public function test_approve_makes_followups_official_and_creates_shipments(): void
    {
        $s = Store::factory()->create();
        $r = $this->pending([$s->id => [10, ['X1' => 'customer_no_answer', 'X2' => 'courier_promised_delivery']]]);

        $this->approve($r)->assertRedirect()->assertSessionHasNoErrors();

        $r->refresh();
        $this->assertSame('approved', $r->status->value);
        $this->assertSame($this->admin->id, $r->approved_by);
        $this->assertTrue($r->followups()->get()->every(fn ($f) => $f->is_official && $f->shipment_id));
        $x1 = Shipment::where('code', 'X1')->sole();
        $this->assertSame($s->id, $x1->store_id);
        $this->assertSame($r->id, $x1->first_report_id);
        $this->assertSame(1, $x1->followups_count);
        $this->assertSame('customer_no_answer', $x1->last_result->value);
        $this->assertSame($this->emp->id, $x1->last_followup_user_id);
    }

    public function test_status_mapping_applied(): void
    {
        $s = Store::factory()->create();
        $codes = [];
        foreach (FollowupResult::cases() as $i => $case) {
            $codes["M{$i}"] = $case->value;
        }
        $this->approve($this->pending([$s->id => [1, $codes]]));

        foreach (FollowupResult::cases() as $i => $case) {
            $ship = Shipment::where('code', "M{$i}")->sole();
            $imp = $case->impliedStatuses();
            $this->assertSame($imp['courier'], $ship->courier_status, $case->value);
            $this->assertSame($imp['customer'], $ship->customer_status, $case->value);
            $this->assertSame(($imp['processing'] ?? \App\Enums\ProcessingStatus::Open), $ship->processing_status, $case->value);
        }
    }

    public function test_metrics_sum_across_reports_and_keep_null(): void
    {
        $s = Store::factory()->create();
        $n = Store::factory()->create();
        $this->approve($this->pending([$s->id => [30, ['A' => 'customer_no_answer']], $n->id => [null, []]]));
        $this->approve($this->pending([$s->id => [12, ['B' => 'delivered_after_followup']], $n->id => [null, []]], $this->makeUser(), ['contact_attempts_count' => 2]));

        $m = StoreDailyMetric::where('store_id', $s->id)->sole();
        $this->assertSame(42, $m->shipments_count);
        $this->assertSame(2, $m->reports_count);
        $this->assertSame(2, $m->followups_count);
        $this->assertSame(1, $m->customer_no_answer);
        $this->assertSame(1, $m->delivered_after_followup);
        $this->assertSame(2, $m->contact_attempts);
        $this->assertNull(StoreDailyMetric::where('store_id', $n->id)->sole()->shipments_count);
    }

    public function test_pending_report_not_in_metrics_or_dashboard(): void
    {
        $s = Store::factory()->create();
        $this->pending([$s->id => [40, ['P' => 'customer_no_answer']]]);
        $this->assertSame(0, StoreDailyMetric::count());
        $this->assertSame(0, Shipment::count());
        $this->assertNull(app(DashboardService::class)->summary(Period::preset('today'), $this->admin)['shipments_reviewed']);
    }

    public function test_reject_requires_reason_and_notifies(): void
    {
        Notification::fake();
        $r = $this->pending([Store::factory()->create()->id => [1, []]]);
        $this->actingAs($this->admin)->post("/reports/{$r->id}/reject", [])->assertSessionHasErrors('rejection_reason');
        $this->actingAs($this->admin)->post("/reports/{$r->id}/reject", ['rejection_reason' => 'ناقص أكواد'])->assertRedirect();

        $r->refresh();
        $this->assertSame('rejected', $r->status->value);
        $this->assertSame('ناقص أكواد', $r->rejection_reason);
        $this->assertSame($this->admin->id, $r->reviewed_by);
        Notification::assertSentTo($this->emp, ReportReviewedNotification::class);
    }

    public function test_approve_notifies_owner(): void
    {
        Notification::fake();
        $this->approve($this->pending([Store::factory()->create()->id => [1, []]]));
        Notification::assertSentTo($this->emp, ReportReviewedNotification::class);
    }

    public function test_reviewer_cannot_approve_own_report(): void
    {
        $r = $this->pending([Store::factory()->create()->id => [1, []]], $this->admin);
        $this->approve($r)->assertForbidden();
        $boss = $this->makeUser(Role::SUPER_ADMIN);
        $own = $this->pending([Store::factory()->create()->id => [1, []]], $boss);
        $this->approve($own, $boss)->assertForbidden();
    }

    public function test_employee_cannot_approve_and_draft_cannot_be_approved(): void
    {
        $r = $this->pending([Store::factory()->create()->id => [1, []]], $this->makeUser());
        $this->approve($r, $this->emp)->assertForbidden();

        $draft = DailyReport::factory()->draft()->for($this->emp)->create();
        $this->approve($draft)->assertSessionHasErrors('status');
    }

    public function test_correct_then_approve_keeps_revision(): void
    {
        $s = Store::factory()->create();
        $r = $this->pending([$s->id => [10, ['C1' => 'customer_no_answer']]]);

        $this->actingAs($this->admin)->put("/reports/{$r->id}", [
            'approve_after' => 1,
            'stores' => [['store_id' => $s->id, 'shipments_count' => 15, 'codes' => [['code' => 'C1', 'result' => 'delivered_after_followup']]]],
        ])->assertSessionHasNoErrors()->assertRedirect(route('reports.show', $r));

        $r->refresh();
        $this->assertSame('approved', $r->status->value);
        $this->assertSame('تصحيح المسؤول', $r->revisions()->sole()->reason);
        $this->assertSame(10, $r->revisions()->sole()->snapshot['stores'][0]['shipments_count']);
        $this->assertSame(15, StoreDailyMetric::where('store_id', $s->id)->sole()->shipments_count);
        $this->assertSame('delivered_after_followup', Shipment::where('code', 'C1')->sole()->last_result->value);
    }

    public function test_reapproval_is_idempotent(): void
    {
        $a = Store::factory()->create();
        $b = Store::factory()->create();
        $r = $this->pending([$a->id => [10, ['K1' => 'customer_no_answer', 'K2' => 'courier_no_answer']]], null, [
            'issue_requests' => [['key' => 'n1', 'store_id' => $a->id, 'type' => 'delivery_delay', 'title' => 'تأخير', 'description' => 'وصف']],
        ]);
        $this->approve($r);
        $this->assertSame(1, Issue::count());

        // تعديل إداري: حذف K2، نقل المتجر من a إلى b مع K1
        $boss = $this->makeUser(Role::SUPER_ADMIN);
        $this->actingAs($boss)->put("/reports/{$r->id}", [
            'stores' => [['store_id' => $a->id, 'shipments_count' => 4, 'codes' => [['code' => 'K1', 'result' => 'customer_no_answer']]], ['store_id' => $b->id, 'shipments_count' => 6]],
            'issue_requests' => [['key' => 'n1', 'store_id' => $a->id, 'type' => 'delivery_delay', 'title' => 'تأخير', 'description' => 'وصف']],
        ])->assertSessionHasNoErrors();

        $r->refresh();
        $this->assertSame('approved', $r->status->value);
        $this->assertSame(1, Issue::count(), 'لا تتكرر المشكلات');
        $this->assertSoftDeleted(Shipment::withTrashed()->where('code', 'K2')->sole());
        $this->assertSame(1, Shipment::where('code', 'K1')->sole()->followups_count);
        $this->assertSame(4, StoreDailyMetric::where('store_id', $a->id)->sole()->shipments_count);
        $this->assertSame(6, StoreDailyMetric::where('store_id', $b->id)->sole()->shipments_count);
        $this->assertSame(0, StoreDailyMetric::where('store_id', $a->id)->sole()->courier_no_answer);
        $this->assertSame('تعديل إداري بعد الاعتماد', $r->revisions()->sole()->reason);
    }

    public function test_removed_code_restored_when_added_again(): void
    {
        $a = Store::factory()->create();
        $r = $this->pending([$a->id => [1, ['R1' => 'courier_no_answer']]]);
        $this->approve($r);
        $boss = $this->makeUser(Role::SUPER_ADMIN);
        $this->actingAs($boss)->put("/reports/{$r->id}", ['stores' => [['store_id' => $a->id]]]);
        $this->assertSame(0, Shipment::count());
        $this->actingAs($boss)->put("/reports/{$r->id}", ['stores' => [['store_id' => $a->id, 'codes' => [['code' => 'R1', 'result' => 'other_issue']]]]])
            ->assertSessionHasNoErrors();
        $this->assertSame('other_issue', Shipment::where('code', 'R1')->sole()->last_result->value);
    }

    public function test_approval_blocked_on_pending_code_conflict(): void
    {
        $s1 = Store::factory()->create();
        $s2 = Store::factory()->create();
        $a = $this->pending([$s1->id => [1, ['X' => 'customer_no_answer']]]);
        $b = $this->pending([$s2->id => [1, ['X' => 'customer_no_answer']]], $this->makeUser());

        $this->approve($a)->assertSessionHasNoErrors();
        $this->approve($b)->assertSessionHasErrors('codes');
        $this->assertSame('pending_review', $b->fresh()->status->value);
        $this->assertFalse($b->followups()->first()->is_official);
    }

    public function test_issue_created_once_from_note(): void
    {
        $s = Store::factory()->create();
        $r = $this->pending([$s->id => [1, []]], null, [
            'issue_requests' => [['key' => 'k', 'store_id' => $s->id, 'type' => 'communication', 'title' => 'لا يرد', 'description' => 'المتجر لا يرد']],
        ]);
        $this->approve($r);

        $issue = Issue::sole();
        $this->assertSame($s->id, $issue->store_id);
        $this->assertSame($r->id, $issue->daily_report_id);
        $this->assertSame($this->emp->id, $issue->reported_by);
        $this->assertSame('new', $issue->status->value);
        $this->assertSame(1, StoreDailyMetric::where('store_id', $s->id)->sole()->issues_opened);
    }

    public function test_review_queue_lists_pending_with_flags(): void
    {
        $this->pending([Store::factory()->create()->id => [1, []]], null, ['attempt_times' => ['1:00', '2:00', '3:00'], 'report_number' => 'RPT-Q-1']);
        $this->actingAs($this->admin)->get('/review')->assertOk()->assertSee('تجاوز حد المحاولات');
        $this->actingAs($this->emp)->get('/review')->assertForbidden();
    }

    public function test_admin_without_admin_edit_cannot_edit_approved(): void
    {
        $r = $this->pending([Store::factory()->create()->id => [1, []]]);
        $this->approve($r);
        $this->actingAs($this->admin)->get("/reports/{$r->id}/edit")->assertForbidden();
        $this->admin->role->permissions()->attach(Permission::where('name', 'reports.admin_edit')->value('id'));
        $this->admin->flushPermissionCache();
        $this->actingAs($this->admin->fresh())->get("/reports/{$r->id}/edit")->assertOk();
    }
}
