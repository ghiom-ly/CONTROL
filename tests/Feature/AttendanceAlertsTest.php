<?php

namespace Tests\Feature;

use App\Models\DailyReport;
use App\Models\Leave;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Notifications\AttendanceAlertNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AttendanceAlertsTest extends TestCase
{
    private User $emp;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        SystemSetting::where('key', 'attendance_start_date')->update(['value' => '2026-11-01']);
        SystemSetting::where('key', 'report_submission_deadline')->update(['value' => '18:00']);
        \Illuminate\Support\Facades\Cache::flush();
        $this->emp = $this->makeUser(Role::EMPLOYEE, ['name' => 'موظف التنبيه']);
        $this->admin = $this->makeUser(Role::ADMIN);
    }

    private function runAt(string $at): void
    {
        $this->travelTo(CarbonImmutable::parse($at));
        $this->artisan('nours:attendance-alerts')->assertSuccessful();
    }

    public function test_not_started_alert_after_late_time(): void
    {
        $this->runAt('2026-11-20 10:05');
        Notification::assertSentTo($this->admin, AttendanceAlertNotification::class,
            fn ($n) => str_contains($n->toArray($this->admin)['message'], 'موظف التنبيه') && $n->kind === 'not_started');
    }

    public function test_no_alert_before_late_time(): void
    {
        $this->runAt('2026-11-20 09:30');
        Notification::assertNothingSent();
    }

    public function test_not_submitted_alert_after_deadline(): void
    {
        DailyReport::factory()->draft()->for($this->emp)->create(['work_date' => '2026-11-20', 'shift_started_at' => '2026-11-20 09:00']);
        $this->runAt('2026-11-20 18:10');
        Notification::assertSentTo($this->admin, AttendanceAlertNotification::class, fn ($n) => $n->kind === 'not_submitted');
        Notification::assertNotSentTo($this->admin, AttendanceAlertNotification::class, fn ($n) => $n->kind === 'not_started');
    }

    public function test_no_alert_on_leave_and_no_duplicates(): void
    {
        $other = $this->makeUser(Role::EMPLOYEE);
        Leave::create(['user_id' => $this->emp->id, 'from_date' => '2026-11-20', 'to_date' => '2026-11-20', 'type' => 'leave']);
        $this->runAt('2026-11-20 10:30');
        $this->runAt('2026-11-20 10:45');
        Notification::assertSentToTimes($this->admin, AttendanceAlertNotification::class, 1);   // للموظف الآخر فقط، مرة واحدة
        $this->assertDatabaseCount('attendance_alerts', 1);
        $this->assertDatabaseHas('attendance_alerts', ['user_id' => $other->id, 'kind' => 'not_started']);
    }

    public function test_admins_not_alerted_about_themselves(): void
    {
        $this->emp->update(['status' => 'inactive']);
        $this->runAt('2026-11-20 11:00');
        Notification::assertNothingSent();
    }

    public function test_nothing_before_start_date(): void
    {
        $this->runAt('2026-10-20 11:00');
        Notification::assertNothingSent();
    }
}
