<?php

namespace Tests\Feature;

use App\Models\AttendanceWaiver;
use App\Models\DailyReport;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\AttendanceService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AttendanceWaiverTest extends TestCase
{
    private User $emp;

    private User $boss;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-20 16:00'));
        SystemSetting::where('key', 'attendance_start_date')->update(['value' => '2026-11-01']);
        SystemSetting::where('key', 'attendance_late_after')->update(['value' => '10:00']);
        SystemSetting::where('key', 'attendance_grace_minutes')->update(['value' => '0']);
        Cache::flush();
        $this->emp = $this->makeUser(Role::EMPLOYEE, ['hired_at' => '2026-01-01', 'created_at' => '2026-01-01']);
        $this->boss = $this->makeUser(Role::SUPER_ADMIN);
    }

    private function setting(string $key, string $value): void
    {
        SystemSetting::where('key', $key)->update(['value' => $value]);
        Cache::flush();
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

    public function test_settings_have_two_toggles_on_by_default(): void
    {
        $this->assertTrue(SystemSetting::get('attendance_track_late'));
        $this->assertTrue(SystemSetting::get('attendance_track_absence'));
        $this->actingAs($this->boss)->get('/settings')->assertOk()
            ->assertSee('احتساب التأخير')->assertSee('احتساب الغياب')->assertSee('إلغاء التأخير والغياب لفترة');
    }

    public function test_settings_form_saves_toggle_off(): void
    {
        $payload = SystemSetting::whereNotIn('key', ['company_logo'])->get()->mapWithKeys(fn ($s) => [$s->key => $s->value])->all();
        $payload['attendance_track_late'] = '0';
        $this->actingAs($this->boss)->put('/settings', ['settings' => $payload])->assertSessionHasNoErrors();
        $this->assertSame('0', SystemSetting::where('key', 'attendance_track_late')->value('value'));
        $this->assertSame('1', SystemSetting::where('key', 'attendance_track_absence')->value('value'));
    }

    public function test_late_toggle_off_cancels_all_lateness(): void
    {
        $this->setting('attendance_track_late', '0');
        $this->shift('2026-11-18 12:00');
        $this->assertNull($this->day('2026-11-18')['late_minutes']);
        $this->assertSame('present', $this->day('2026-11-18')['status']);
        $this->assertSame('absent', $this->day('2026-11-17')['status']);
    }

    public function test_absence_toggle_off_cancels_all_absence(): void
    {
        $this->setting('attendance_track_absence', '0');
        $this->shift('2026-11-18 12:00');
        $this->assertSame('not_counted', $this->day('2026-11-17')['status']);
        $this->assertSame(120, $this->day('2026-11-18')['late_minutes']);
        $r = app(AttendanceService::class)->rangeForAll(collect([$this->emp]), CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-19'));
        $this->assertSame(0, $r[$this->emp->id]['absent']);
        $this->assertSame(1, $r[$this->emp->id]['late']);
    }

    public function test_period_waiver_cancels_late_only_inside_period(): void
    {
        AttendanceWaiver::create(['from_date' => '2026-11-10', 'to_date' => '2026-11-12', 'cancel_late' => true, 'cancel_absence' => false, 'reason' => 'عطل']);
        $this->shift('2026-11-11 12:00');
        $this->shift('2026-11-13 12:00');
        $this->assertNull($this->day('2026-11-11')['late_minutes']);
        $this->assertSame(120, $this->day('2026-11-13')['late_minutes']);
        $this->assertSame('absent', $this->day('2026-11-10')['status']);
    }

    public function test_period_waiver_cancels_absence_as_excused(): void
    {
        AttendanceWaiver::create(['from_date' => '2026-11-10', 'to_date' => '2026-11-12', 'cancel_late' => false, 'cancel_absence' => true, 'reason' => 'انقطاع النت']);
        $d = $this->day('2026-11-10');
        $this->assertSame('excused', $d['status']);
        $this->assertSame('انقطاع النت', $d['leave_type']);
        $this->assertSame('absent', $this->day('2026-11-14')['status']);   // 13 جمعة
        $r = app(AttendanceService::class)->rangeForAll(collect([$this->emp]), CarbonImmutable::parse('2026-11-10'), CarbonImmutable::parse('2026-11-14'));
        $this->assertSame(1, $r[$this->emp->id]['absent']);
    }

    public function test_boss_creates_and_deletes_waiver(): void
    {
        $this->actingAs($this->boss)->post('/settings/attendance-waivers', [
            'from_date' => '2026-11-10', 'to_date' => '2026-11-12', 'cancel_late' => '1', 'reason' => 'عطل في المنظومة',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $w = AttendanceWaiver::sole();
        $this->assertTrue($w->cancel_late);
        $this->assertFalse($w->cancel_absence);
        $this->assertSame($this->boss->id, $w->created_by);
        $this->actingAs($this->boss)->get('/settings')->assertSee('عطل في المنظومة');

        $this->actingAs($this->boss)->delete("/settings/attendance-waivers/{$w->id}")->assertRedirect();
        $this->assertSame(0, AttendanceWaiver::count());
    }

    public function test_waiver_validation(): void
    {
        $this->actingAs($this->boss)->post('/settings/attendance-waivers', ['from_date' => '2026-11-12', 'to_date' => '2026-11-10', 'cancel_late' => '1'])
            ->assertSessionHasErrors('to_date');
        $this->actingAs($this->boss)->post('/settings/attendance-waivers', ['from_date' => '2026-11-10', 'to_date' => '2026-11-12'])
            ->assertSessionHasErrors('cancel_late');
        $this->actingAs($this->boss)->post('/settings/attendance-waivers', ['from_date' => '2026-01-01', 'to_date' => '2026-11-12', 'cancel_late' => '1'])
            ->assertSessionHasErrors('to_date');
        $this->assertSame(0, AttendanceWaiver::count());
    }

    public function test_non_super_admin_cannot_manage_waivers(): void
    {
        $admin = $this->makeUser(Role::ADMIN);
        $this->actingAs($admin)->post('/settings/attendance-waivers', ['from_date' => '2026-11-10', 'to_date' => '2026-11-12', 'cancel_late' => '1'])
            ->assertForbidden();
        $w = AttendanceWaiver::create(['from_date' => '2026-11-10', 'to_date' => '2026-11-12', 'cancel_late' => true, 'cancel_absence' => true]);
        $this->actingAs($admin)->delete("/settings/attendance-waivers/{$w->id}")->assertForbidden();
        $this->assertSame(1, AttendanceWaiver::count());
    }

    public function test_no_not_started_alert_when_late_off_or_waived_today(): void
    {
        Notification::fake();
        $admin = $this->makeUser(Role::ADMIN);
        $this->travelTo(CarbonImmutable::parse('2026-11-19 11:00'));   // خميس
        $this->setting('attendance_track_late', '0');
        $this->artisan('nours:attendance-alerts')->assertSuccessful();
        Notification::assertNothingSent();

        $this->setting('attendance_track_late', '1');
        AttendanceWaiver::create(['from_date' => '2026-11-19', 'to_date' => '2026-11-19', 'cancel_late' => false, 'cancel_absence' => true]);
        $this->artisan('nours:attendance-alerts')->assertSuccessful();
        Notification::assertNothingSent();

        AttendanceWaiver::query()->delete();
        $this->artisan('nours:attendance-alerts')->assertSuccessful();
        Notification::assertSentTo($admin, \App\Notifications\AttendanceAlertNotification::class, fn ($n) => $n->kind === 'not_started');
    }
}
