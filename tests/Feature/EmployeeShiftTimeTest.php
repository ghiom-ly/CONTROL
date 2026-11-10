<?php

namespace Tests\Feature;

use App\Models\DailyReport;
use App\Models\EmployeeShiftTime;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Notifications\AttendanceAlertNotification;
use App\Services\AttendanceService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class EmployeeShiftTimeTest extends TestCase
{
    private User $emp;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-20 16:00'));
        SystemSetting::where('key', 'attendance_start_date')->update(['value' => '2026-11-01']);
        SystemSetting::where('key', 'attendance_late_after')->update(['value' => '10:00']);
        SystemSetting::where('key', 'attendance_grace_minutes')->update(['value' => '0']);
        \Illuminate\Support\Facades\Cache::flush();
        $this->emp = $this->makeUser(Role::EMPLOYEE, ['hired_at' => '2026-01-01', 'created_at' => '2026-01-01']);
        $this->admin = $this->makeUser(Role::ADMIN);
    }

    private function update(array $extra)
    {
        $u = $this->emp;

        return $this->actingAs($this->makeUser(Role::SUPER_ADMIN))->put("/users/{$u->id}", $extra + [
            'name' => $u->name, 'username' => $u->username, 'role_id' => $u->role_id, 'status' => 'active', 'work_type' => 'followup',
        ]);
    }

    private function shift(string $start): void
    {
        $s = CarbonImmutable::parse($start);
        DailyReport::factory()->for($this->emp)->create(['work_date' => $s->toDateString(), 'shift_started_at' => $s,
            'status' => 'approved', 'submitted_at' => $s->addHours(6), 'first_submitted_at' => $s->addHours(6)]);
    }

    private function day(string $date): array
    {
        $m = app(AttendanceService::class)->month($this->emp, CarbonImmutable::parse($date)->startOfMonth());

        return collect($m['days'])->firstWhere('date', $date);
    }

    public function test_employee_shift_time_replaces_global_late_time(): void
    {
        $this->update(['shift_start' => '14:00', 'shift_effective_from' => '2026-11-20'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('14:00', EmployeeShiftTime::sole()->start_time);
        $this->shift('2026-11-20 13:55');
        $this->assertNull($this->day('2026-11-20')['late_minutes']);
        $this->assertSame('present', $this->day('2026-11-20')['status']);
    }

    public function test_late_counted_from_employee_shift_time(): void
    {
        $this->update(['shift_start' => '14:00', 'shift_effective_from' => '2026-11-20']);
        $this->shift('2026-11-20 14:30');
        $this->assertSame(30, $this->day('2026-11-20')['late_minutes']);
    }

    public function test_change_does_not_affect_days_before_effective_date(): void
    {
        $this->shift('2026-11-19 11:00');
        $this->update(['shift_start' => '12:00', 'shift_effective_from' => '2026-11-20']);
        $this->assertSame(60, $this->day('2026-11-19')['late_minutes']);
    }

    public function test_history_picks_latest_effective_row(): void
    {
        EmployeeShiftTime::create(['user_id' => $this->emp->id, 'start_time' => '08:00', 'effective_from' => '2026-11-01']);
        EmployeeShiftTime::create(['user_id' => $this->emp->id, 'start_time' => '15:00', 'effective_from' => '2026-11-15']);
        $this->shift('2026-11-10 09:00');
        $this->shift('2026-11-16 14:00');
        $this->assertSame(60, $this->day('2026-11-10')['late_minutes']);
        $this->assertNull($this->day('2026-11-16')['late_minutes']);
    }

    public function test_clearing_reverts_to_general_time(): void
    {
        EmployeeShiftTime::create(['user_id' => $this->emp->id, 'start_time' => '14:00', 'effective_from' => '2026-11-01']);
        $this->update(['shift_start' => '', 'shift_effective_from' => '2026-11-20']);
        $this->shift('2026-11-20 11:00');
        $this->assertSame(60, $this->day('2026-11-20')['late_minutes']);
        $this->assertNull(EmployeeShiftTime::currentFor($this->emp->id));
    }

    public function test_same_value_does_not_add_row_and_same_day_replaces(): void
    {
        $this->update(['shift_start' => '14:00', 'shift_effective_from' => '2026-11-20']);
        $this->update(['shift_start' => '14:00', 'shift_effective_from' => '2026-11-20']);
        $this->update(['shift_start' => '13:00', 'shift_effective_from' => '2026-11-20']);
        $this->assertSame(['13:00'], EmployeeShiftTime::pluck('start_time')->all());
    }

    public function test_invalid_time_rejected(): void
    {
        $this->update(['shift_start' => '25:99', 'shift_effective_from' => '2026-11-20'])->assertSessionHasErrors('shift_start');
        $this->assertSame(0, EmployeeShiftTime::count());
    }

    public function test_range_for_all_uses_employee_shift(): void
    {
        EmployeeShiftTime::create(['user_id' => $this->emp->id, 'start_time' => '14:00', 'effective_from' => '2026-11-01']);
        $this->shift('2026-11-18 13:00');
        $r = app(AttendanceService::class)->rangeForAll(collect([$this->emp]), CarbonImmutable::parse('2026-11-18'), CarbonImmutable::parse('2026-11-18'));
        $this->assertSame(0, $r[$this->emp->id]['late']);
    }

    public function test_not_started_alert_waits_for_employee_shift(): void
    {
        Notification::fake();
        EmployeeShiftTime::create(['user_id' => $this->emp->id, 'start_time' => '14:00', 'effective_from' => '2026-11-01']);
        $this->travelTo(CarbonImmutable::parse('2026-11-19 11:00'));   // خميس
        $this->artisan('nours:attendance-alerts')->assertSuccessful();
        Notification::assertNothingSent();
        $this->travelTo(CarbonImmutable::parse('2026-11-19 14:05'));
        $this->artisan('nours:attendance-alerts')->assertSuccessful();
        Notification::assertSentTo($this->admin, AttendanceAlertNotification::class, fn ($n) => $n->kind === 'not_started');
    }

    public function test_pages_show_shift_time(): void
    {
        EmployeeShiftTime::create(['user_id' => $this->emp->id, 'start_time' => '14:00', 'effective_from' => '2026-11-01']);
        $this->actingAs($this->admin)->get("/users/{$this->emp->id}")->assertSee('وقت بداية الشفت')->assertSee('14:00');
        $this->actingAs($this->admin)->get("/attendance/{$this->emp->id}")->assertSee('14:00');
    }
}
