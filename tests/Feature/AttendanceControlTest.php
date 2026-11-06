<?php

namespace Tests\Feature;

use App\Models\AttendanceOverride;
use App\Models\DailyReport;
use App\Models\Holiday;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreDailyMetric;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\AttendanceService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class AttendanceControlTest extends TestCase
{
    private User $emp;

    private User $boss;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-20 12:00'));
        SystemSetting::where('key', 'attendance_start_date')->update(['value' => '2026-11-01']);
        SystemSetting::where('key', 'attendance_late_after')->update(['value' => '10:00']);
        \Illuminate\Support\Facades\Cache::flush();
        $this->emp = $this->makeUser(Role::EMPLOYEE, ['hired_at' => '2026-01-01', 'created_at' => '2026-01-01']);
        $this->boss = $this->makeUser(Role::SUPER_ADMIN);
    }

    private function day(string $date): array
    {
        return collect(app(AttendanceService::class)->month($this->emp, CarbonImmutable::parse($date)->startOfMonth())['days'])->firstWhere('date', $date);
    }

    public function test_excuse_absence_and_undo(): void
    {
        $this->assertSame('absent', $this->day('2026-11-17')['status']);
        $this->actingAs($this->boss)->post("/attendance/{$this->emp->id}/overrides", ['date' => '2026-11-17', 'type' => 'excused', 'reason' => 'ظرف عائلي'])
            ->assertSessionHasNoErrors();
        $this->assertSame('excused', $this->day('2026-11-17')['status']);
        $sum = app(AttendanceService::class)->month($this->emp, CarbonImmutable::parse('2026-11-01'))['summary'];
        $this->assertSame(1, $sum['excused']);

        $o = AttendanceOverride::sole();
        $this->actingAs($this->boss)->delete("/attendance/overrides/{$o->id}")->assertRedirect();
        $this->assertSame('absent', $this->day('2026-11-17')['status']);
    }

    public function test_mark_present(): void
    {
        $this->actingAs($this->boss)->post("/attendance/{$this->emp->id}/overrides", ['date' => '2026-11-17', 'type' => 'present', 'reason' => 'عمل من الخارج']);
        $this->assertSame('present', $this->day('2026-11-17')['status']);
        $this->assertTrue($this->day('2026-11-17')['manual']);
    }

    public function test_excuse_lateness(): void
    {
        DailyReport::factory()->approved()->for($this->emp)->create(['work_date' => '2026-11-18', 'shift_started_at' => '2026-11-18 11:00', 'first_submitted_at' => '2026-11-18 18:00']);
        $this->assertSame('late', $this->day('2026-11-18')['status']);
        $this->actingAs($this->boss)->post("/attendance/{$this->emp->id}/overrides", ['date' => '2026-11-18', 'type' => 'late_excused', 'reason' => 'زحمة']);
        $d = $this->day('2026-11-18');
        $this->assertSame('present', $d['status']);
        $this->assertNull($d['late_minutes']);
    }

    public function test_holiday_not_absent(): void
    {
        $this->actingAs($this->boss)->post('/attendance/holidays', ['date' => '2026-11-16', 'name' => 'عيد'])->assertSessionHasNoErrors();
        $this->assertSame('holiday', $this->day('2026-11-16')['status']);
        $this->actingAs($this->boss)->delete('/attendance/holidays/'.Holiday::sole()->id)->assertRedirect();
        $this->assertSame('absent', $this->day('2026-11-16')['status']);
    }

    public function test_missing_report_alert_skips_holiday_and_excused(): void
    {
        $this->seed(\Database\Seeders\AlertRulesSeeder::class);
        \App\Models\AlertRule::where('key', '!=', 'employee_missing_report')->update(['is_enabled' => false]);
        $run = fn () => app(\App\Alerts\AlertEngine::class)->run(CarbonImmutable::parse('2026-11-20 19:00'));

        \App\Models\AttendanceOverride::create(['user_id' => $this->emp->id, 'date' => '2026-11-20', 'type' => 'excused']);
        $run();
        $this->assertSame(0, \App\Models\Alert::count());

        \App\Models\AttendanceOverride::query()->delete();
        Holiday::create(['date' => '2026-11-20', 'name' => 'عطلة']);
        $run();
        $this->assertSame(0, \App\Models\Alert::count());
    }

    public function test_only_super_admin(): void
    {
        $admin = $this->makeUser(Role::ADMIN);
        $this->actingAs($admin)->post("/attendance/{$this->emp->id}/overrides", ['date' => '2026-11-17', 'type' => 'excused'])->assertForbidden();
        $this->actingAs($admin)->post('/attendance/holidays', ['date' => '2026-11-16', 'name' => 'x'])->assertForbidden();
        $this->actingAs($this->emp)->post("/attendance/{$this->emp->id}/overrides", ['date' => '2026-11-17', 'type' => 'excused'])->assertForbidden();
    }

    public function test_edit_shift_time_moves_day(): void
    {
        $r = DailyReport::factory()->approved()->for($this->emp)->create(['work_date' => '2026-11-18', 'shift_started_at' => '2026-11-18 09:00', 'first_submitted_at' => '2026-11-18 17:00']);
        $this->assertSame('absent', $this->day('2026-11-19')['status']);

        $this->actingAs($this->boss)->put("/reports/{$r->id}/shift-time", ['shift_started_at' => '2026-11-19T09:30', 'first_submitted_at' => '2026-11-19T17:30'])
            ->assertSessionHasNoErrors();
        $r->refresh();
        $this->assertSame('2026-11-19', $r->work_date->toDateString());
        $this->assertSame('present', $this->day('2026-11-19')['status']);
        $this->assertSame('absent', $this->day('2026-11-18')['status']);

        $this->actingAs($this->boss)->put("/reports/{$r->id}/shift-time", ['shift_started_at' => '2026-11-19T09:30', 'first_submitted_at' => '2026-11-19T08:00'])
            ->assertSessionHasErrors('first_submitted_at');
        $this->actingAs($this->makeUser(Role::ADMIN))->put("/reports/{$r->id}/shift-time", ['shift_started_at' => '2026-11-19T09:30'])->assertForbidden();
    }

    public function test_edit_shift_time_rebuilds_metrics(): void
    {
        $s = Store::factory()->create();
        $r = DailyReport::factory()->pendingReview()->for($this->emp)->create(['work_date' => '2026-11-18', 'shift_started_at' => '2026-11-18 09:00']);
        \App\Models\ReportStore::create(['daily_report_id' => $r->id, 'store_id' => $s->id, 'shipments_count' => 7]);
        $this->actingAs($this->makeUser(Role::ADMIN))->post("/reports/{$r->id}/approve");
        $this->assertSame('2026-11-18', StoreDailyMetric::sole()->metric_date->toDateString());

        $this->actingAs($this->boss)->put("/reports/{$r->id}/shift-time", ['shift_started_at' => '2026-11-19T09:00'])->assertSessionHasNoErrors();
        $this->assertSame('2026-11-19', StoreDailyMetric::sole()->metric_date->toDateString());
        $this->assertSame(7, StoreDailyMetric::sole()->shipments_count);
    }
}
