<?php

namespace Tests\Feature;

use App\Models\DailyReport;
use App\Models\Permission;
use App\Models\ReportStore;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\ShipmentFollowup;
use App\Models\Store;
use App\Models\User;
use App\Services\Reports\ReportApprovalService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** اختبارات تثبّت ملاحظات المراجعة النهائية */
class ReviewFixesTest extends TestCase
{
    private User $emp;

    private User $admin;

    private User $boss;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->emp = $this->makeUser();
        $this->admin = $this->makeUser(Role::ADMIN);
        $this->boss = $this->makeUser(Role::SUPER_ADMIN);
        $this->store = Store::factory()->create();
    }

    private function pending(array $codes, ?User $owner = null, string $status = 'pending_review'): DailyReport
    {
        $owner ??= $this->emp;
        $r = DailyReport::factory()->state(['status' => $status, 'submitted_at' => now()])->for($owner)->create();
        $rs = ReportStore::create(['daily_report_id' => $r->id, 'store_id' => $this->store->id, 'shipments_count' => 1]);
        foreach ($codes as $code => $result) {
            ShipmentFollowup::create(['daily_report_id' => $r->id, 'report_store_id' => $rs->id, 'store_id' => $this->store->id,
                'shipment_code' => $code, 'user_id' => $owner->id, 'followup_at' => now(), 'result' => $result]);
        }

        return $r;
    }

    private function adminEdit(DailyReport $r, array $codes, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->boss)->put("/reports/{$r->id}", ['stores' => [[
            'store_id' => $this->store->id, 'shipments_count' => 1, 'notes' => 'تعديل',
            'codes' => collect($codes)->map(fn ($res, $c) => ['code' => $c, 'result' => $res])->values()->all(),
        ]]]);
    }

    // C1
    public function test_admin_edit_of_old_report_keeps_newer_result(): void
    {
        $old = $this->pending(['RF-X-1' => 'customer_no_answer']);
        app(ReportApprovalService::class)->approve($old, $this->admin);
        $this->travel(1)->days();
        $new = $this->pending(['RF-X-1' => 'delivered_after_followup'], $this->makeUser());
        app(ReportApprovalService::class)->approve($new, $this->admin);
        $this->travel(1)->days();

        $this->adminEdit($old, ['RF-X-1' => 'customer_no_answer'])->assertSessionHasNoErrors();

        $s = Shipment::where('code', 'RF-X-1')->sole();
        $this->assertSame('delivered_after_followup', $s->last_result->value);
        $this->assertSame('resolved', $s->processing_status->value);
        $this->assertSame($new->user_id, $s->last_followup_user_id);
    }

    // I1
    public function test_rejected_version_is_kept_as_revision(): void
    {
        $r = $this->pending(['RF-OLD-1' => 'other_issue'], null, 'rejected');
        $this->actingAs($this->emp)->put("/reports/{$r->id}", ['stores' => [[
            'store_id' => $this->store->id, 'codes' => [['code' => 'RF-NEW-1', 'result' => 'other_issue']],
        ]]])->assertSessionHasNoErrors();
        $this->actingAs($this->emp)->post("/reports/{$r->id}/submit", ['end_delivery_rate' => 50])->assertSessionHasNoErrors();

        $rev = $r->revisions()->sole();
        $this->assertSame(['RF-OLD-1'], collect($rev->snapshot['followups'])->pluck('code')->all());
        $this->assertSame(2, $r->fresh()->version);
    }

    // I3
    public function test_approve_rechecks_status_inside_lock(): void
    {
        $r = $this->pending([]);
        $service = app(ReportApprovalService::class);
        $service->approve($r, $this->admin);

        $this->expectException(ValidationException::class);
        $service->approve($r, $this->admin);   // نموذج قديم: لا اعتماد مزدوج
    }

    public function test_cannot_approve_a_report_rejected_meanwhile(): void
    {
        $r = $this->pending([]);
        $stale = DailyReport::find($r->id);
        app(ReportApprovalService::class)->reject($r, 'سبب', $this->admin);

        $this->expectException(ValidationException::class);
        app(ReportApprovalService::class)->approve($stale, $this->admin);
    }

    // I4
    public function test_screens_upload_with_main_form_save(): void
    {
        Storage::fake('private');
        $r = DailyReport::factory()->draft()->for($this->emp)->create();
        $this->actingAs($this->emp)->put("/reports/{$r->id}", [
            'stores' => [['store_id' => $this->store->id, 'shipments_count' => 3]],
            'screens' => ['courier_screens' => [UploadedFile::fake()->image('c.png')], 'customer_calls' => [UploadedFile::fake()->image('d.png')]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, $r->screens('courier_screens')->count());
        $this->assertSame(1, $r->screens('customer_calls')->count());
        $this->assertSame(3, $r->stores()->sole()->shipments_count);
    }

    // I5
    public function test_restored_shipment_takes_new_first_report(): void
    {
        $r1 = $this->pending(['RF-Y-1' => 'courier_no_answer']);
        app(ReportApprovalService::class)->approve($r1, $this->admin);
        $this->adminEdit($r1, []);
        $this->assertSoftDeleted(Shipment::withTrashed()->where('code', 'RF-Y-1')->sole());

        $r2 = $this->pending(['RF-Y-1' => 'courier_no_answer'], $this->makeUser());
        app(ReportApprovalService::class)->approve($r2, $this->admin);
        $this->assertSame($r2->id, Shipment::where('code', 'RF-Y-1')->sole()->first_report_id);

        $this->adminEdit($r2, []);
        $this->assertSame(0, Shipment::count(), 'لا تبقى شحنة رسمية بلا متابعات');
    }

    // M1
    public function test_author_cannot_reapprove_own_report_via_admin_edit(): void
    {
        $this->admin->role->permissions()->attach(Permission::where('name', 'reports.admin_edit')->value('id'));
        $r = $this->pending([], $this->admin);
        app(ReportApprovalService::class)->approve($r, $this->boss);

        $this->adminEdit($r, [], $this->admin->fresh())->assertForbidden();
    }

    // M3
    public function test_blank_code_rows_are_ignored(): void
    {
        $r = DailyReport::factory()->draft()->for($this->emp)->create();
        $this->actingAs($this->emp)->put("/reports/{$r->id}", ['stores' => [[
            'store_id' => $this->store->id, 'codes' => [['code' => '', 'result' => 'other_issue'], ['code' => 'RF-Z-1', 'result' => 'other_issue']],
        ]]])->assertSessionHasNoErrors();
        $this->assertSame(['RF-Z-1'], ShipmentFollowup::pluck('shipment_code')->all());
    }
}
