<?php

namespace Tests\Feature;

use App\Models\DailyReport;
use App\Models\ImportBatch;
use App\Models\ReportStore;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreAlias;
use App\Models\SystemSetting;
use App\Services\AttendanceService;
use App\Services\Imports\StoreListImporter;
use App\Support\StartTotals;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesSpreadsheets;
use Tests\TestCase;

/** اختبارات تثبّت ملاحظات المراجعة النهائية للمرحلة 4 */
class Phase4ReviewFixesTest extends TestCase
{
    use MakesSpreadsheets;

    private function storeImport(array $rows): array
    {
        Storage::fake('private');
        $admin = $this->makeUser(Role::ADMIN);
        $svc = app(StoreListImporter::class);
        $b = $svc->upload($this->xlsx($rows, 's.xlsx'), $admin);
        $svc->saveMapping($b, ['name' => 'الاسم', 'phone' => 'الهاتف', 'owner' => 'المالك']);

        return $svc->preview($b->fresh());
    }

    // I1
    public function test_alias_and_name_of_same_store_in_file_rejected(): void
    {
        $s = Store::factory()->create(['name' => 'متجر الأمل']);
        StoreAlias::create(['store_id' => $s->id, 'alias' => 'الأمل للتجارة', 'normalized_alias' => 'الامل للتجاره']);
        $p = $this->storeImport([['الاسم', 'الهاتف', 'المالك'], ['متجر الأمل', '0911111111', null], ['الأمل للتجارة', '0922222222', null]]);
        $this->assertSame(['update', 'rejected'], array_column($p['rows'], 'action'));
        $this->assertSame('مكرر في الملف', $p['rows'][1]['reason']);
    }

    // m1 (أُعيد تصنيفها: خطأ 500 يُفشل الاستيراد كله)
    public function test_overlong_cells_rejected_not_500(): void
    {
        $p = $this->storeImport([['الاسم', 'الهاتف', 'المالك'], [str_repeat('م', 201), null, null], ['متجر', null, str_repeat('س', 151)], ['م', null, null]]);
        $this->assertSame(['rejected', 'rejected', 'rejected'], array_column($p['rows'], 'action'));
    }

    // I2
    public function test_file_started_report_has_no_false_overall_rate_mismatch(): void
    {
        $emp = $this->makeUser();
        $a = Store::factory()->create();
        $b = Store::factory()->create();
        $r = DailyReport::factory()->draft()->for($emp)->create(['file_values' => ['start_total' => 7, 'start_rate' => 57.14]]);
        ReportStore::create(['daily_report_id' => $r->id, 'store_id' => $a->id, 'start_orders' => 1, 'start_rate' => 0, 'start_source' => 'file', 'file_orders_count' => 1, 'file_delivered_count' => 0]);
        ReportStore::create(['daily_report_id' => $r->id, 'store_id' => $b->id, 'start_orders' => 6, 'start_rate' => 66.67, 'start_source' => 'file', 'file_orders_count' => 6, 'file_delivered_count' => 4]);
        StartTotals::refresh($r);
        $this->assertSame('57.14', $r->fresh()->start_delivery_rate);
        $this->assertSame([], $r->fresh()->fileDiscrepancies());
    }

    // m2
    public function test_clearing_all_start_values_clears_report_totals(): void
    {
        $emp = $this->makeUser();
        $s = Store::factory()->assignedTo($emp->id)->create();
        $this->actingAs($emp)->post('/reports/start', ['stores' => [['store_id' => $s->id, 'start_orders' => 10, 'start_rate' => 50]]]);
        $r = DailyReport::sole();
        $this->actingAs($emp)->put("/reports/{$r->id}", ['stores' => [['store_id' => $s->id, 'start_orders' => '', 'start_rate' => '']]])->assertSessionHasNoErrors();
        $this->assertNull($r->fresh()->start_total_orders);
        $this->assertNull($r->fresh()->start_delivery_rate);
    }

    // I3
    public function test_migration_inserts_attendance_settings_when_missing(): void
    {
        SystemSetting::whereIn('key', ['attendance_late_after', 'attendance_grace_minutes', 'attendance_start_date'])->delete();
        $migration = require database_path('migrations/2026_11_07_000100_phase4.php');
        $migration->ensureSettings();
        \Illuminate\Support\Facades\Cache::flush();
        $this->assertSame('10:00', SystemSetting::get('attendance_late_after'));
        $this->assertSame(today()->toDateString(), SystemSetting::get('attendance_start_date'));
    }

    // I4
    public function test_shift_submitted_next_day_is_incomplete(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-20 12:00'));
        SystemSetting::where('key', 'attendance_start_date')->update(['value' => '2026-11-01']);
        \Illuminate\Support\Facades\Cache::flush();
        $emp = $this->makeUser(Role::EMPLOYEE, ['hired_at' => '2026-01-01']);
        DailyReport::factory()->approved()->for($emp)->create(['work_date' => '2026-11-09', 'shift_started_at' => '2026-11-09 09:00', 'first_submitted_at' => '2026-11-10 09:30']);
        $d = collect(app(AttendanceService::class)->month($emp, CarbonImmutable::parse('2026-11-01'))['days'])->firstWhere('date', '2026-11-09');
        $this->assertSame('incomplete', $d['status']);
        $this->assertNull($d['out']);
        $this->assertNull($d['minutes']);
    }

    // I5
    public function test_assignment_shows_stores_held_by_non_employees(): void
    {
        $gone = $this->makeUser(Role::EMPLOYEE, ['status' => 'inactive']);
        Store::factory()->assignedTo($gone->id)->create(['name' => 'متجر عند موظف موقوف']);
        $this->actingAs($this->makeUser(Role::ADMIN))->get('/stores/assignment')->assertOk()
            ->assertSee('متجر عند موظف موقوف')->assertSee('مُسندة لغير موظف نشط');
    }

    // m4
    public function test_no_alert_for_employee_hired_in_future(): void
    {
        Notification::fake();
        SystemSetting::where('key', 'attendance_start_date')->update(['value' => '2026-11-01']);
        \Illuminate\Support\Facades\Cache::flush();
        $this->makeUser(Role::EMPLOYEE, ['hired_at' => '2026-12-01']);
        $this->makeUser(Role::ADMIN);
        $this->travelTo(CarbonImmutable::parse('2026-11-20 11:00'));
        $this->artisan('nours:attendance-alerts')->assertSuccessful();
        Notification::assertNothingSent();
    }
}
