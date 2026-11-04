<?php

namespace Tests\Feature;

use App\Models\DailyReport;
use App\Models\ImportBatch;
use App\Models\ReportStore;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\ShipmentFollowup;
use App\Models\Store;
use App\Models\StoreAlias;
use App\Models\StoreDailyMetric;
use App\Models\User;
use App\Services\Reports\ReportApprovalService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LegacyImportTest extends TestCase
{
    private User $emp;

    private User $admin;

    private Store $amal;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        $this->emp = $this->makeUser('employee', ['name' => 'أحمد سالم']);
        $this->admin = $this->makeUser(Role::ADMIN);
        $this->amal = Store::factory()->assignedTo($this->emp->id)->create(['name' => 'متجر الأمل']);
    }

    private function text(string $date = '2026-10-20', string $codes = 'LG-A1 LG-A2', string $store = 'متجر الأمل'): string
    {
        return "ريبورت تاريخ: {$date}\nاسم الموظف: أحمد سالم\n\n1) ملخص حركة الأوردرات:\n- {$store}: 34\n\n2) أوقات المحاولات:\n- المحاولة الأولى: الساعة 10:30\n\n3) الأكواد غير المُجاب عليها:\n- أكواد لم يرد عليها الزبون (لتحويلها للمتاجر): {$codes}\n\n4) ملاحظات:\n- قديم";
    }

    /** لصق ← معاينة ← إنشاء أول تقرير */
    private function import(User $as, string $text, array $override = [])
    {
        $this->actingAs($as)->post('/imports/legacy/preview', ['text' => $text])->assertRedirect(route('imports.legacy.review'));

        return $this->actingAs($as)->post('/imports/legacy', array_merge(['index' => 0, 'user_id' => $this->emp->id], $override));
    }

    public function test_paste_preview_and_create_pending_report(): void
    {
        $this->actingAs($this->emp)->post('/imports/legacy/preview', ['text' => $this->text()]);
        $this->actingAs($this->emp)->get('/imports/legacy/review')->assertOk()->assertSee('2026-10-20')->assertSee('متجر الأمل');

        $this->actingAs($this->emp)->post('/imports/legacy', ['index' => 0, 'user_id' => $this->emp->id])->assertSessionHasNoErrors();

        $r = DailyReport::sole();
        $this->assertTrue($r->is_legacy);
        $this->assertSame('import', $r->source);
        $this->assertSame('pending_review', $r->status->value);
        $this->assertSame('2026-10-20', $r->work_date->toDateString());
        $this->assertSame(34, $r->total_shipments);
        $this->assertSame(['10:30'], $r->attempt_times);
        $this->assertSame('قديم', $r->notes);
        $this->assertSame(['LG-A1', 'LG-A2'], $r->followups()->orderBy('id')->pluck('shipment_code')->all());
        $this->assertTrue($r->followups()->get()->every(fn ($f) => $f->followup_at->toDateString() === '2026-10-20' && $f->store_id === $this->amal->id));

        $b = ImportBatch::sole();
        $this->assertSame('legacy_whatsapp', $b->report_type);
        $this->assertSame('text', $b->file_type);
        $this->assertSame($r->id, $b->daily_report_id);
        $this->assertSame($b->id, $r->import_batch_id);
        Storage::disk('private')->assertExists($b->stored_path);
    }

    public function test_employee_cannot_import_for_others(): void
    {
        $other = $this->makeUser();
        $this->import($this->emp, $this->text(), ['user_id' => $other->id])->assertForbidden();
        $this->assertSame(0, DailyReport::count());
    }

    public function test_admin_imports_for_employee(): void
    {
        $this->import($this->admin, $this->text())->assertSessionHasNoErrors();
        $this->assertSame($this->emp->id, DailyReport::sole()->user_id);
    }

    public function test_duplicate_date_warns(): void
    {
        DailyReport::factory()->approved()->for($this->emp)->create(['work_date' => '2026-10-20']);
        $this->actingAs($this->emp)->post('/imports/legacy/preview', ['text' => $this->text()]);
        $this->actingAs($this->emp)->get('/imports/legacy/review')->assertSee('يوجد تقرير لنفس الموظف في هذا التاريخ');
    }

    public function test_missing_date_cannot_be_created(): void
    {
        $this->import($this->emp, str_replace('2026-10-20', '??', $this->text()))->assertSessionHasErrors('date');
        $this->assertSame(0, DailyReport::count());
    }

    public function test_unmatched_store_choice_saved_as_alias(): void
    {
        $this->import($this->emp, $this->text(store: 'الأمل - الفرع'), ['store_choices' => ['الأمل - الفرع' => $this->amal->id]])
            ->assertSessionHasNoErrors();
        $this->assertSame($this->amal->id, StoreAlias::sole()->store_id);
        $this->assertSame($this->amal->id, ReportStore::sole()->store_id);
    }

    public function test_legacy_approval_is_historical(): void
    {
        $this->import($this->emp, $this->text());
        $r = DailyReport::sole();
        app(ReportApprovalService::class)->approve($r, $this->admin);

        $s = Shipment::where('code', 'LG-A1')->sole();
        $this->assertSame('closed', $s->processing_status->value);
        $this->assertNull($s->last_result);
        $this->assertSame(1, $s->followups_count);
        $this->assertTrue(ShipmentFollowup::where('shipment_code', 'LG-A1')->sole()->is_historical);
        $this->actingAs($this->admin)->get('/open-codes?tab=customer')->assertOk()->assertDontSee('LG-A1');
        $m = StoreDailyMetric::where('store_id', $this->amal->id)->sole();
        $this->assertSame('2026-10-20', $m->metric_date->toDateString());
        $this->assertSame(2, $m->customer_no_answer);
        $this->assertSame(34, $m->shipments_count);
    }

    private function normalApproved(Store $store, string $code, string $result): DailyReport
    {
        $r = DailyReport::factory()->pendingReview()->for($this->makeUser())->create();
        $rs = ReportStore::create(['daily_report_id' => $r->id, 'store_id' => $store->id, 'shipments_count' => 1]);
        ShipmentFollowup::create(['daily_report_id' => $r->id, 'report_store_id' => $rs->id, 'store_id' => $store->id,
            'shipment_code' => $code, 'user_id' => $r->user_id, 'followup_at' => now(), 'result' => $result]);
        app(ReportApprovalService::class)->approve($r, $this->admin);

        return $r;
    }

    public function test_legacy_conflict_warns_and_keeps_current_state(): void
    {
        $other = Store::factory()->create();
        $this->normalApproved($other, 'LG-C1', 'courier_no_answer');

        $this->import($this->emp, $this->text(codes: 'LG-C1'))->assertSessionHasNoErrors();
        $r = DailyReport::where('is_legacy', true)->sole();
        $this->actingAs($this->admin)->post("/reports/{$r->id}/approve")->assertSessionHasNoErrors()->assertSessionHas('warning');

        $s = Shipment::where('code', 'LG-C1')->sole();
        $this->assertSame($other->id, $s->store_id);
        $this->assertSame('courier_no_answer', $s->last_result->value);
        $this->assertSame('open', $s->processing_status->value);
        $this->assertSame('approved', $r->fresh()->status->value);
        $this->assertSame(2, $s->followups_count);
    }

    public function test_legacy_followup_does_not_become_latest(): void
    {
        $this->normalApproved($this->amal, 'LG-L1', 'courier_promised_delivery');
        $this->import($this->emp, $this->text(codes: 'LG-L1'));
        app(ReportApprovalService::class)->approve(DailyReport::where('is_legacy', true)->sole(), $this->admin);

        $this->assertSame('courier_promised_delivery', Shipment::where('code', 'LG-L1')->sole()->last_result->value);
    }
}
