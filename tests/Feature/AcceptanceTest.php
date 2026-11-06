<?php

namespace Tests\Feature;

use App\Alerts\AlertEngine;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\DailyReport;
use App\Models\Issue;
use App\Models\Leave;
use App\Models\MessageTemplate;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\StoreDailyMetric;
use App\Models\StoreMessage;
use App\Models\SystemSetting;
use App\Models\User;
use App\Reports\ReportRegistry;
use App\Services\AttendanceService;
use App\Services\Imports\ShiftFileImporter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\MakesSpreadsheets;
use Tests\TestCase;

/** اختبارات القبول النهائية: 18 سيناريو تمر على المنظومة كما يستخدمها الناس */
class AcceptanceTest extends TestCase
{
    use MakesSpreadsheets;

    private User $emp;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->emp = $this->makeUser(Role::EMPLOYEE, ['name' => 'موظف القبول']);
        $this->admin = $this->makeUser(Role::ADMIN, ['name' => 'مسؤول القبول']);
    }

    /** موظف يبدأ شفتاً ويحفظ متاجر وأكواداً ثم يرسل — يعيد التقرير */
    private function shift(array $stores, int $endRate = 70): DailyReport
    {
        $this->actingAs($this->emp)->post('/reports/start', ['stores' => array_map(fn ($s) => ['store_id' => $s->id, 'start_orders' => 50, 'start_rate' => 60], $stores)])
            ->assertRedirect();
        $r = DailyReport::where('user_id', $this->emp->id)->latest('id')->first();
        $this->actingAs($this->emp)->put("/reports/{$r->id}", ['stores' => array_map(fn ($s, $i) => [
            'store_id' => $s->id, 'shipments_count' => 10 + $i,
            'codes' => [['code' => "ACC{$s->id}", 'result' => 'customer_no_answer']],
        ], $stores, array_keys($stores))])->assertSessionHasNoErrors();
        $this->actingAs($this->emp)->post("/reports/{$r->id}/submit", ['end_delivery_rate' => $endRate])->assertSessionHasNoErrors();

        return $r->fresh();
    }

    private function approve(DailyReport $r): void
    {
        $this->actingAs($this->admin)->post("/reports/{$r->id}/approve")->assertRedirect();
    }

    public function test_01_shift_submit_approve_reaches_metrics_and_analytics(): void
    {
        [$a, $b] = Store::factory()->count(2)->assignedTo($this->emp->id)->create();
        $r = $this->shift([$a, $b]);
        $this->assertSame('pending_review', $r->status->value);
        $this->approve($r);
        $this->assertSame(10, StoreDailyMetric::where('store_id', $a->id)->sole()->shipments_count);
        $this->actingAs($this->admin)->get('/analytics?period=day')->assertOk()->assertSee('21');
    }

    public function test_02_reject_notify_edit_resubmit_approve(): void
    {
        $s = Store::factory()->assignedTo($this->emp->id)->create();
        $r = $this->shift([$s]);
        $this->actingAs($this->admin)->post("/reports/{$r->id}/reject", ['rejection_reason' => 'ناقص'])->assertRedirect();
        $this->assertSame(1, $this->emp->notifications()->count());
        $this->actingAs($this->emp)->get("/reports/{$r->id}")->assertSee('عدّل التقرير وأعد إرساله');
        $this->actingAs($this->emp)->post("/reports/{$r->id}/submit", ['end_delivery_rate' => 75])->assertSessionHasNoErrors();
        $this->approve($r);
        $this->assertSame('approved', $r->fresh()->status->value);
    }

    public function test_03_shift_file_fills_report(): void
    {
        Storage::fake('private');
        $s = Store::factory()->assignedTo($this->emp->id)->create(['name' => 'متجر الملف']);
        $svc = app(ShiftFileImporter::class);
        $b = $svc->upload($this->xlsx([['المتجر', 'الإجمالي', 'المسلم'], ['متجر الملف', 40, 30]]), 'shift_start', null, $this->emp)['batch'];
        $svc->saveMapping($b, ['layout' => 'stores', 'columns' => ['store' => 'المتجر', 'total' => 'الإجمالي', 'delivered' => 'المسلم']], []);
        $this->actingAs($this->emp)->post("/imports/{$b->id}/apply")->assertRedirect();
        $r = DailyReport::sole();
        $this->assertSame(40, $r->start_total_orders);
        $this->assertSame('75.00', $r->start_delivery_rate);
    }

    public function test_04_store_import_then_assignment_shows_in_employee_form(): void
    {
        Storage::fake('private');
        $this->actingAs($this->admin)->post('/stores/import', ['file' => $this->xlsx([['اسم المتجر', 'الهاتف', 'صاحب المتجر'], ['متجر مستورد', '0911111111', 'علي']], 's.xlsx')]);
        $batch = \App\Models\ImportBatch::latest('id')->first();
        $this->actingAs($this->admin)->post("/stores/import/{$batch->id}/mapping", ['columns' => ['name' => 'اسم المتجر', 'phone' => 'الهاتف', 'owner' => 'صاحب المتجر']]);
        $this->actingAs($this->admin)->post("/stores/import/{$batch->id}/apply")->assertRedirect();
        $s = Store::where('name', 'متجر مستورد')->sole();
        $this->actingAs($this->admin)->post('/stores/assignment', ['store_ids' => [$s->id], 'user_id' => $this->emp->id])->assertRedirect();
        $this->assertSame($this->emp->id, $s->fresh()->assigned_user_id);
        $this->actingAs($this->emp)->get('/reports/new')->assertOk()->assertSee('متجر مستورد');
    }

    public function test_05_attendance_late_and_leave(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-20 12:00'));
        SystemSetting::where('key', 'attendance_start_date')->update(['value' => '2026-11-01']);
        SystemSetting::where('key', 'attendance_late_after')->update(['value' => '10:00']);
        cache()->flush();
        $emp = $this->makeUser(Role::EMPLOYEE, ['hired_at' => '2026-01-01']);
        DailyReport::factory()->approved()->for($emp)->create(['work_date' => '2026-11-18', 'shift_started_at' => '2026-11-18 10:30', 'first_submitted_at' => '2026-11-18 18:00']);
        Leave::create(['user_id' => $emp->id, 'from_date' => '2026-11-19', 'to_date' => '2026-11-19', 'type' => 'leave']);
        $days = collect(app(AttendanceService::class)->month($emp, CarbonImmutable::parse('2026-11-01'))['days'])->keyBy('date');
        $this->assertSame('late', $days['2026-11-18']['status']);
        $this->assertSame('leave', $days['2026-11-19']['status']);
        $this->assertSame('absent', $days['2026-11-17']['status']);
    }

    public function test_06_open_codes_listed_and_updated_after_followup(): void
    {
        $s = Store::factory()->assignedTo($this->emp->id)->create();
        $this->approve($this->shift([$s]));
        $this->actingAs($this->admin)->get('/open-codes')->assertOk()->assertSee("ACC{$s->id}");
        $this->assertSame(1, Shipment::where('code', "ACC{$s->id}")->sole()->followups_count);
    }

    public function test_07_template_approved_and_sent_to_store(): void
    {
        $s = Store::factory()->create(['phone' => '0912345678', 'owner_name' => 'علي']);
        $t = MessageTemplate::where('type', 'general')->first();
        $t->update(['status' => 'approved', 'version' => 1]);
        $this->actingAs($this->admin)->post("/stores/{$s->id}/message", ['template_id' => $t->id, 'body' => 'مرحبا'])
            ->assertRedirect('https://wa.me/218912345678?text='.rawurlencode('مرحبا'));
        $this->assertSame(1, StoreMessage::count());
    }

    public function test_08_code_lookup_summary_vs_full_and_external_link(): void
    {
        $s = Store::factory()->assignedTo($this->emp->id)->create();
        $this->approve($this->shift([$s]));
        $code = "ACC{$s->id}";
        $this->actingAs($this->makeUser())->get("/shipments/{$code}")->assertOk()->assertDontSee('سجل المتابعات الكامل');
        $this->actingAs($this->admin)->get("/shipments/{$code}")->assertOk()->assertSee('سجل المتابعات الكامل')
            ->assertSee('https://backoffice.nawris.algoriza.com/orders/'.$code, false);
    }

    public function test_09_contacts_logged_and_counted(): void
    {
        $s = Store::factory()->create();
        Shipment::create(['code' => 'CNT1', 'store_id' => $s->id, 'registered_at' => today(), 'processing_status' => 'open']);
        $this->actingAs($this->emp)->post('/shipments/CNT1/contacts', ['party_type' => 'customer', 'result' => 'answered'])->assertRedirect();
        $this->actingAs($this->admin)->post("/stores/{$s->id}/contacts", ['result' => 'no_answer'])->assertRedirect();
        $this->actingAs($this->admin)->get('/contacts')->assertOk()->assertSee('موظف القبول')->assertSee('100%');
    }

    public function test_10_issue_from_report_assign_resolve_close(): void
    {
        $s = Store::factory()->assignedTo($this->emp->id)->create();
        $r = $this->shift([$s]);
        $r->update(['issue_requests' => [['key' => 'k', 'store_id' => $s->id, 'type' => 'other', 'title' => 'مشكلة القبول', 'description' => 'x']]]);
        $this->approve($r);
        $i = Issue::sole();
        $this->actingAs($this->admin)->post("/issues/{$i->id}/updates", ['status' => 'in_progress', 'assigned_to' => $this->admin->id])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post("/issues/{$i->id}/updates", ['status' => 'resolved', 'action_taken' => 'تم'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post("/issues/{$i->id}/updates", ['status' => 'closed'])->assertSessionHasNoErrors();
        $this->assertSame('closed', $i->fresh()->status->value);
        $this->assertNotNull($i->fresh()->closed_at);
    }

    public function test_11_analytics_compares_two_periods(): void
    {
        $s = Store::factory()->create();
        StoreDailyMetric::create(['store_id' => $s->id, 'metric_date' => today()->subDays(8), 'shipments_count' => 10]);
        StoreDailyMetric::create(['store_id' => $s->id, 'metric_date' => today(), 'shipments_count' => 15]);
        $this->actingAs($this->admin)->get('/analytics?period=week')->assertOk()->assertSee('+50%');
    }

    public function test_12_alert_opens_closes_and_appears_in_whatsapp_summary(): void
    {
        $this->seed(\Database\Seeders\AlertRulesSeeder::class);
        AlertRule::where('key', '!=', 'issue_resolution_delay')->update(['is_enabled' => false]);
        $i = Issue::create(['store_id' => Store::factory()->create()->id, 'reported_by' => $this->admin->id, 'reported_at' => now()->subDays(5),
            'type' => 'other', 'title' => 'تنبيه القبول', 'description' => 'd', 'status' => 'new']);
        app(AlertEngine::class)->run(CarbonImmutable::now());
        $this->actingAs($this->admin)->get('/alerts/whatsapp')->assertOk()->assertSee('تنبيه القبول');
        $i->update(['status' => 'resolved', 'resolved_at' => now(), 'action_taken' => 'x']);
        app(AlertEngine::class)->run(CarbonImmutable::now());
        $this->assertSame('closed', Alert::sole()->status);
    }

    public function test_13_bulk_delete_archives_stores_with_history(): void
    {
        $s = Store::factory()->assignedTo($this->emp->id)->create();
        $this->approve($this->shift([$s]));
        $clean = Store::factory()->create();
        $this->actingAs($this->makeUser(Role::SUPER_ADMIN))->delete('/stores/bulk', ['store_ids' => [$s->id, $clean->id]])->assertRedirect();
        $this->assertSame('archived', $s->fresh()->status->value);
        $this->assertNull(Store::withTrashed()->find($clean->id));
    }

    public function test_14_every_report_every_format(): void
    {
        $s = Store::factory()->create();
        foreach (array_keys(ReportRegistry::all()) as $key) {
            foreach (['pdf', 'excel', 'print'] as $f) {
                $this->actingAs($this->admin)->get("/reports-center/{$key}/{$f}?store_id={$s->id}&user_id={$this->emp->id}")->assertOk();
            }
        }
    }

    public function test_15_store_report_link_works_without_login_and_expires(): void
    {
        $s = Store::factory()->create();
        $url = URL::temporarySignedRoute('store-report.public', now()->addDays(7), ['store' => $s->id, 'from' => today()->toDateString(), 'to' => today()->toDateString()]);
        $this->get($url)->assertOk();
        $this->travel(8)->days();
        $this->get($url)->assertForbidden();
    }

    public function test_16_employee_reaches_no_admin_page(): void
    {
        foreach (['/analytics', '/alerts', '/reports-center', '/issues', '/contacts', '/stores', '/users', '/settings', '/audit-logs', '/open-codes'] as $url) {
            $this->actingAs($this->emp)->get($url)->assertForbidden();
        }
    }

    public function test_17_backup_creates_file(): void
    {
        // mysqldump موجود على الخادم؛ بيئة التطوير المحمولة قد لا تملكه على PATH
        if (! \Illuminate\Support\Facades\Process::run(['mysqldump', '--version'])->successful()) {
            $this->markTestSkipped('mysqldump غير متاح في هذه البيئة.');
        }
        $this->artisan('nours:backup', ['--keep' => 1])->assertSuccessful();
        $this->assertNotEmpty(glob(storage_path('backups/nours_backup_*.zip')));
    }

    public function test_18_cache_flushed_after_approval(): void
    {
        $this->actingAs($this->admin)->get('/analytics?period=day')->assertOk();
        $s = Store::factory()->assignedTo($this->emp->id)->create();
        $this->approve($this->shift([$s]));
        $row = collect(app(\App\Services\Analytics\AnalyticsService::class)->compare(\App\Support\ComparePeriod::fromRequest(\Illuminate\Http\Request::create('/', 'GET', ['period' => 'day']))))
            ->firstWhere('key', 'orders');
        $this->assertSame(10.0, $row['current']);
    }
}
