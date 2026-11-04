<?php

namespace Tests\Feature;

use App\Models\DailyReport;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\ShipmentFollowup;
use App\Models\Store;
use App\Models\User;
use Tests\TestCase;

class ReportDraftTest extends TestCase
{
    private User $emp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->emp = $this->makeUser();
    }

    private function draft(array $attrs = []): DailyReport
    {
        return DailyReport::factory()->draft()->for($this->emp)->create($attrs);
    }

    private function save(DailyReport $r, array $payload)
    {
        return $this->actingAs($this->emp)->put("/reports/{$r->id}", $payload);
    }

    public function test_employee_starts_shift(): void
    {
        $this->actingAs($this->emp)->post('/reports/start', ['start_total_orders' => '١٤٠', 'start_delivery_rate' => 72])
            ->assertRedirect();

        $r = DailyReport::sole();
        $this->assertSame('draft', $r->status->value);
        $this->assertSame(140, $r->start_total_orders);
        $this->assertSame('72.00', $r->start_delivery_rate);
        $this->assertNotNull($r->shift_started_at);
        $this->assertSame(today()->toDateString(), $r->work_date->toDateString());
        $this->assertSame($this->emp->id, $r->user_id);
    }

    public function test_start_validates_rate_range(): void
    {
        $this->actingAs($this->emp)->post('/reports/start', ['start_total_orders' => 10, 'start_delivery_rate' => 120])
            ->assertSessionHasErrors('start_delivery_rate');
    }

    public function test_cannot_start_second_open_shift(): void
    {
        $this->actingAs($this->emp);
        $this->post('/reports/start', ['start_total_orders' => 10, 'start_delivery_rate' => 50]);
        $this->post('/reports/start', ['start_total_orders' => 10, 'start_delivery_rate' => 50])->assertSessionHasErrors('shift');
        $this->assertSame(1, DailyReport::count());

        // تقرير مرفوض لا يمنع شفتاً جديداً
        DailyReport::query()->update(['status' => 'rejected']);
        $this->post('/reports/start', ['start_total_orders' => 10, 'start_delivery_rate' => 50])->assertSessionHasNoErrors();
        $this->assertSame(2, DailyReport::count());
    }

    public function test_new_report_page_redirects_to_open_draft(): void
    {
        $r = $this->draft();
        $this->actingAs($this->emp)->get('/reports/new')->assertRedirect(route('reports.edit', $r));
    }

    public function test_save_draft_with_store_codes_and_unanswered(): void
    {
        $s1 = Store::factory()->assignedTo($this->emp->id)->create();
        $s2 = Store::factory()->assignedTo($this->emp->id)->create();
        $r = $this->draft();

        $this->save($r, [
            'stores' => [
                ['store_id' => $s1->id, 'shipments_count' => 34, 'codes' => [['code' => 'nr-1', 'result' => 'courier_promised_delivery']]],
                ['store_id' => $s2->id, 'shipments_count' => 21],
            ],
            'unanswered' => ['customer' => "A1 A2", 'customer_store_id' => $s1->id],
            'attempt_times' => ['10:30', '14:15'],
            'notes' => 'ملاحظة', 'inquiries' => 'سؤال',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $r->refresh();
        $this->assertSame(2, $r->stores()->count());
        $this->assertSame(['10:30', '14:15'], $r->attempt_times);
        $this->assertSame('ملاحظة', $r->notes);
        $f = ShipmentFollowup::orderBy('id')->get();
        $this->assertSame(['NR-1', 'A1', 'A2'], $f->pluck('shipment_code')->all());
        $this->assertSame(['courier_promised_delivery', 'customer_no_answer', 'customer_no_answer'], $f->pluck('result')->map->value->all());
        $this->assertTrue($f->every(fn ($x) => ! $x->is_official && $x->shipment_id === null && $x->store_id === $s1->id));
        $this->assertSame(0, Shipment::count());

        // الحفظ مرة أخرى يستبدل ولا يكرر
        $this->save($r, ['stores' => [['store_id' => $s1->id, 'shipments_count' => 30]]])->assertSessionHasNoErrors();
        $this->assertSame(1, $r->stores()->count());
        $this->assertSame(0, ShipmentFollowup::count());
    }

    public function test_card_result_wins_over_unanswered_list_duplicate(): void
    {
        $s1 = Store::factory()->create();
        $r = $this->draft();
        $this->save($r, [
            'stores' => [['store_id' => $s1->id, 'codes' => [['code' => 'X1', 'result' => 'courier_no_answer']]]],
            'unanswered' => ['courier' => 'X1', 'courier_store_id' => $s1->id],
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, ShipmentFollowup::count());
    }

    public function test_duplicate_code_in_report_rejected(): void
    {
        $s1 = Store::factory()->create();
        $s2 = Store::factory()->create();
        $r = $this->draft();
        $this->save($r, ['stores' => [
            ['store_id' => $s1->id, 'codes' => [['code' => 'D1', 'result' => 'other_issue']]],
            ['store_id' => $s2->id, 'codes' => [['code' => 'd1', 'result' => 'other_issue']]],
        ]])->assertSessionHasErrors('codes');
        $this->assertSame(0, ShipmentFollowup::count());
    }

    public function test_code_of_other_store_rejected(): void
    {
        $a = Store::factory()->create();
        $b = Store::factory()->create();
        Shipment::create(['code' => 'OWN1', 'store_id' => $a->id, 'registered_at' => today()]);
        $r = $this->draft();

        $this->save($r, ['stores' => [['store_id' => $b->id, 'codes' => [['code' => 'OWN1', 'result' => 'other_issue']]]]])
            ->assertSessionHasErrors('codes');

        // في القائمة المباشرة يأخذ الكود المعروف متجره تلقائياً
        $this->save($r, ['stores' => [['store_id' => $a->id]], 'unanswered' => ['courier' => 'OWN1']])->assertSessionHasNoErrors();
        $this->assertSame($a->id, ShipmentFollowup::sole()->store_id);
    }

    public function test_unanswered_code_needs_a_store_of_the_report(): void
    {
        $s1 = Store::factory()->create();
        $outside = Store::factory()->create();
        $r = $this->draft();
        $this->save($r, ['stores' => [['store_id' => $s1->id]], 'unanswered' => ['customer' => 'Z1']])
            ->assertSessionHasErrors('unanswered.customer_store_id');
        $this->save($r, ['stores' => [['store_id' => $s1->id]], 'unanswered' => ['customer' => 'Z1', 'customer_store_id' => $outside->id]])
            ->assertSessionHasErrors('unanswered.customer_store_id');
    }

    public function test_pending_conflict_is_warning_only(): void
    {
        $a = Store::factory()->create();
        $b = Store::factory()->create();
        $other = DailyReport::factory()->pendingReview()->for($this->makeUser())->create();
        ShipmentFollowup::create(['daily_report_id' => $other->id, 'store_id' => $a->id, 'shipment_code' => 'P1',
            'user_id' => $other->user_id, 'followup_at' => now(), 'result' => 'other_issue']);

        $r = $this->draft();
        $this->save($r, ['stores' => [['store_id' => $b->id, 'codes' => [['code' => 'P1', 'result' => 'other_issue']]]]])
            ->assertSessionHasNoErrors()->assertSessionHas('warning');
        $this->assertSame(2, ShipmentFollowup::count());
    }

    public function test_over_limit_attempts_requires_reason(): void
    {
        $s1 = Store::factory()->create();
        $r = $this->draft();
        $payload = ['stores' => [['store_id' => $s1->id]], 'attempt_times' => ['09:00', '11:00', '13:00']];
        $this->save($r, $payload)->assertSessionHasErrors('attempts_over_limit_reason');
        $this->save($r, $payload + ['attempts_over_limit_reason' => 'ضغط طلبيات'])->assertSessionHasNoErrors();
        $this->assertCount(3, $r->fresh()->attempt_times);
    }

    public function test_outside_assignment_flag_set(): void
    {
        $mine = Store::factory()->assignedTo($this->emp->id)->create();
        $theirs = Store::factory()->assignedTo($this->makeUser()->id)->create();
        $r = $this->draft();
        $this->save($r, ['stores' => [['store_id' => $mine->id], ['store_id' => $theirs->id]]])->assertSessionHasNoErrors();
        $flags = $r->stores()->pluck('is_outside_assignment', 'store_id');
        $this->assertFalse($flags[$mine->id]);
        $this->assertTrue($flags[$theirs->id]);
    }

    public function test_archived_store_not_allowed(): void
    {
        $s = Store::factory()->create(['status' => 'archived']);
        $this->save($this->draft(), ['stores' => [['store_id' => $s->id]]])->assertSessionHasErrors('stores.0.store_id');
    }

    public function test_null_shipments_count_stays_null(): void
    {
        $s = Store::factory()->create();
        $r = $this->draft();
        $this->save($r, ['stores' => [['store_id' => $s->id, 'shipments_count' => '']]])->assertSessionHasNoErrors();
        $this->assertNull($r->stores()->sole()->shipments_count);
    }

    public function test_issue_requests_saved_on_report(): void
    {
        $s = Store::factory()->create();
        $r = $this->draft();
        $this->save($r, ['stores' => [['store_id' => $s->id]], 'issue_requests' => [
            ['key' => 'k1', 'store_id' => $s->id, 'type' => 'delivery_delay', 'title' => 'تأخير', 'description' => 'تأخر المندوب'],
        ]])->assertSessionHasNoErrors();
        $this->assertSame('k1', $r->fresh()->issue_requests[0]['key']);
    }

    public function test_employee_cannot_edit_other_users_report(): void
    {
        $r = DailyReport::factory()->draft()->for($this->makeUser())->create();
        $this->actingAs($this->emp)->get("/reports/{$r->id}/edit")->assertForbidden();
        $this->actingAs($this->emp)->put("/reports/{$r->id}", ['stores' => []])->assertForbidden();
    }

    public function test_cannot_edit_pending_report_as_owner(): void
    {
        $r = DailyReport::factory()->pendingReview()->for($this->emp)->create();
        $this->actingAs($this->emp)->get("/reports/{$r->id}/edit")->assertForbidden();
    }

    public function test_viewer_cannot_start_shift(): void
    {
        $this->actingAs($this->makeUser(Role::VIEWER))->post('/reports/start', ['start_total_orders' => 1, 'start_delivery_rate' => 1])->assertForbidden();
    }

    public function test_edit_page_renders(): void
    {
        $s = Store::factory()->assignedTo($this->emp->id)->create(['name' => 'متجر الأمل']);
        $r = $this->draft();
        $this->save($r, ['stores' => [['store_id' => $s->id, 'shipments_count' => 5]]]);
        $this->actingAs($this->emp)->get("/reports/{$r->id}/edit")->assertOk()->assertSee($s->code)->assertSee('بداية الشفت');
    }
}
