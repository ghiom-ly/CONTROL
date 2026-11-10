<?php

namespace Tests\Feature;

use App\Models\DailyReport;
use App\Models\Leave;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\AttendanceService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class AttendanceServiceTest extends TestCase
{
    private User $emp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-20 12:00'));
        SystemSetting::where('key', 'attendance_start_date')->update(['value' => '2026-11-01']);
        SystemSetting::where('key', 'attendance_late_after')->update(['value' => '10:00']);
        \Illuminate\Support\Facades\Cache::flush();
        $this->emp = $this->makeUser(Role::EMPLOYEE, ['hired_at' => '2026-01-01', 'created_at' => '2026-01-01']);
    }

    private function shift(string $start, ?string $firstSubmit, array $attrs = []): DailyReport
    {
        $s = CarbonImmutable::parse($start);

        return DailyReport::factory()->for($this->emp)->create(array_merge([
            'work_date' => $s->toDateString(), 'shift_started_at' => $s, 'status' => $firstSubmit ? 'approved' : 'draft',
            'submitted_at' => $firstSubmit, 'first_submitted_at' => $firstSubmit,
        ], $attrs));
    }

    private function day(string $date): array
    {
        $m = app(AttendanceService::class)->month($this->emp, CarbonImmutable::parse($date)->startOfMonth());

        return collect($m['days'])->firstWhere('date', $date);
    }

    public function test_present_late_and_minutes(): void
    {
        $this->shift('2026-11-02 10:20', '2026-11-02 16:20');
        $d = $this->day('2026-11-02');
        $this->assertSame('late', $d['status']);
        $this->assertSame('10:20', $d['in']);
        $this->assertSame('16:20', $d['out']);
        $this->assertSame(360, $d['minutes']);
        $this->assertSame(20, $d['late_minutes']);

        $this->shift('2026-11-03 09:55', '2026-11-03 15:00');
        $this->assertSame('present', $this->day('2026-11-03')['status']);
    }

    public function test_grace_minutes(): void
    {
        SystemSetting::where('key', 'attendance_grace_minutes')->update(['value' => '15']);
        \Illuminate\Support\Facades\Cache::flush();
        $this->shift('2026-11-02 10:10', '2026-11-02 16:00');
        $this->assertSame('present', $this->day('2026-11-02')['status']);
    }

    public function test_absent_and_leave(): void
    {
        Leave::create(['user_id' => $this->emp->id, 'from_date' => '2026-11-04', 'to_date' => '2026-11-05', 'type' => 'sick']);
        $this->assertSame('absent', $this->day('2026-11-03')['status']);
        $this->assertSame('leave', $this->day('2026-11-04')['status']);
        $this->assertSame('مرضية', $this->day('2026-11-05')['leave_type']);
        $this->assertSame('weekend', $this->day('2026-11-06')['status']);   // الجمعة عطلة لا غياب
    }

    public function test_future_and_pre_start_days_not_absent(): void
    {
        $nov = app(AttendanceService::class)->month($this->emp, CarbonImmutable::parse('2026-11-01'));
        $this->assertSame('future', collect($nov['days'])->firstWhere('date', '2026-11-25')['status']);
        $this->assertSame('absent', collect($nov['days'])->firstWhere('date', '2026-11-19')['status']);
        $oct = app(AttendanceService::class)->month($this->emp, CarbonImmutable::parse('2026-10-01'));
        $this->assertSame([], array_filter(array_column($oct['days'], 'status'), fn ($s) => $s === 'absent'));
        $this->assertSame(0, $oct['summary']['absent']);
        // اليوم الحالي لا يُحسب غياباً قبل نهايته (يوم عمل — الخميس)
        $this->travelTo(CarbonImmutable::parse('2026-11-19 12:00'));
        $nov = app(AttendanceService::class)->month($this->emp, CarbonImmutable::parse('2026-11-01'));
        $this->assertSame('future', collect($nov['days'])->firstWhere('date', '2026-11-19')['status']);
    }

    public function test_incomplete_shift(): void
    {
        $this->shift('2026-11-10 09:00', null);
        $this->assertSame('incomplete', $this->day('2026-11-10')['status']);
    }

    public function test_clock_out_uses_first_submission(): void
    {
        $this->shift('2026-11-11 09:00', '2026-11-11 15:00', ['submitted_at' => '2026-11-13 11:00']);
        $d = $this->day('2026-11-11');
        $this->assertSame('15:00', $d['out']);
        $this->assertSame(360, $d['minutes']);
    }

    public function test_legacy_day_counts_present_without_times(): void
    {
        DailyReport::factory()->approved()->for($this->emp)->create(['work_date' => '2026-11-12', 'is_legacy' => true, 'source' => 'import', 'shift_started_at' => null]);
        $d = $this->day('2026-11-12');
        $this->assertSame('present', $d['status']);
        $this->assertTrue($d['legacy']);
        $this->assertNull($d['in']);
    }

    public function test_two_shifts_same_day(): void
    {
        $this->shift('2026-11-14 09:00', '2026-11-14 12:00');
        $this->shift('2026-11-14 14:00', '2026-11-14 18:00');
        $d = $this->day('2026-11-14');
        $this->assertSame('09:00', $d['in']);
        $this->assertSame('18:00', $d['out']);
        $this->assertSame(420, $d['minutes']);
    }

    public function test_admins_not_in_population(): void
    {
        $admin = $this->makeUser(Role::ADMIN);
        $ids = app(AttendanceService::class)->employees()->pluck('id')->all();
        $this->assertContains($this->emp->id, $ids);
        $this->assertNotContains($admin->id, $ids);
    }

    public function test_summary_counts(): void
    {
        $this->shift('2026-11-02 10:30', '2026-11-02 16:30', ['status' => 'approved']);
        $this->shift('2026-11-03 09:00', '2026-11-03 13:00', ['status' => 'rejected']);
        $this->shift('2026-11-04 09:00', null);
        Leave::create(['user_id' => $this->emp->id, 'from_date' => '2026-11-05', 'to_date' => '2026-11-05', 'type' => 'leave']);

        $s = app(AttendanceService::class)->month($this->emp, CarbonImmutable::parse('2026-11-01'))['summary'];
        $this->assertSame(3, $s['present']);
        $this->assertSame(1, $s['late']);
        $this->assertSame(30, $s['late_minutes']);
        $this->assertSame(1, $s['incomplete']);
        $this->assertSame(1, $s['leave']);
        $this->assertSame(19 - 4 - 2, $s['absent']);   // 1..19 نوفمبر ماضية، منها 4 غير غياب وجمعتان (6 و13)
        $this->assertSame(300, $s['avg_minutes']);  // (360 + 240) / 2
        $this->assertSame(3, $s['reports_total']);
        $this->assertSame(1, $s['reports_approved']);
        $this->assertSame(1, $s['reports_rejected']);
    }

    public function test_hired_after_start_date(): void
    {
        $late = $this->makeUser(Role::EMPLOYEE, ['hired_at' => '2026-11-15']);
        $days = app(AttendanceService::class)->month($late, CarbonImmutable::parse('2026-11-01'))['days'];
        $this->assertSame('not_counted', collect($days)->firstWhere('date', '2026-11-10')['status']);
        $this->assertSame('absent', collect($days)->firstWhere('date', '2026-11-16')['status']);
    }
}
