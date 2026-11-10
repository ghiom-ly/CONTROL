<?php

namespace Tests\Feature;

use App\Models\DailyReport;
use App\Models\EmployeeSalary;
use App\Models\PayrollAdjustment;
use App\Models\PayrollItem;
use App\Models\PayrollMonth;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\AttendanceService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PayrollTest extends TestCase
{
    private User $emp;

    private User $boss;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-12-05 12:00'));
        SystemSetting::where('key', 'attendance_start_date')->update(['value' => '2026-11-01']);
        SystemSetting::where('key', 'attendance_late_after')->update(['value' => '10:00']);
        SystemSetting::where('key', 'attendance_grace_minutes')->update(['value' => '0']);
        Cache::flush();
        $this->emp = $this->makeUser(Role::EMPLOYEE, ['name' => 'سالم', 'phone' => '0912345678', 'hired_at' => '2026-01-01', 'created_at' => '2026-01-01']);
        $this->boss = $this->makeUser(Role::SUPER_ADMIN);
        EmployeeSalary::create(['user_id' => $this->emp->id, 'amount' => 900, 'effective_from' => '2026-01-01']);
    }

    /** شفت كامل (حضور 09:00 وإرسال 15:00) لكل يوم غير الجمعة في نوفمبر عدا المستثنى */
    private function fillNovember(User $u, array $skip = [], string $from = '2026-11-01'): void
    {
        for ($d = CarbonImmutable::parse($from); $d->month === 11; $d = $d->addDay()) {
            if ($d->isFriday() || in_array($d->toDateString(), $skip, true)) {
                continue;
            }
            $this->shift($u, $d->toDateString().' 09:00');
        }
    }

    private function shift(User $u, string $start, bool $submitted = true): void
    {
        $s = CarbonImmutable::parse($start);
        DailyReport::factory()->for($u)->create(['work_date' => $s->toDateString(), 'shift_started_at' => $s,
            'status' => $submitted ? 'approved' : 'draft',
            'submitted_at' => $submitted ? $s->addHours(6) : null, 'first_submitted_at' => $submitted ? $s->addHours(6) : null]);
    }

    /** نوفمبر: غياب 3 و4، شفت غير مكتمل 5، تأخير ساعتين 9 */
    private function standardMonth(): void
    {
        $this->fillNovember($this->emp, ['2026-11-03', '2026-11-04', '2026-11-05', '2026-11-09']);
        $this->shift($this->emp, '2026-11-05 09:00', submitted: false);
        $this->shift($this->emp, '2026-11-09 12:00');
    }

    private function compute(string $month = '2026-11')
    {
        return $this->actingAs($this->boss)->post('/payroll/compute', ['month' => $month]);
    }

    private function item(?User $u = null): PayrollItem
    {
        return PayrollItem::where('user_id', ($u ?? $this->emp)->id)->sole();
    }

    // ---------------- الجمعة عطلة

    public function test_friday_is_weekend_not_absence(): void
    {
        $days = collect(app(AttendanceService::class)->month($this->emp, CarbonImmutable::parse('2026-11-01'))['days']);
        $this->assertSame('weekend', $days->firstWhere('date', '2026-11-06')['status']);
        $this->assertSame('absent', $days->firstWhere('date', '2026-11-05')['status']);
        $this->assertSame(0, app(AttendanceService::class)->rangeForAll(collect([$this->emp]), CarbonImmutable::parse('2026-11-06'), CarbonImmutable::parse('2026-11-06'))[$this->emp->id]['absent']);
    }

    public function test_friday_shift_still_counts_as_present(): void
    {
        $this->shift($this->emp, '2026-11-13 09:00');
        $days = collect(app(AttendanceService::class)->month($this->emp, CarbonImmutable::parse('2026-11-01'))['days']);
        $this->assertSame('present', $days->firstWhere('date', '2026-11-13')['status']);
    }

    public function test_no_not_started_alert_on_friday(): void
    {
        Notification::fake();
        $this->makeUser(Role::ADMIN);
        $this->travelTo(CarbonImmutable::parse('2026-12-04 13:00'));   // جمعة
        $this->artisan('nours:attendance-alerts')->assertSuccessful();
        Notification::assertNothingSent();
    }

    // ---------------- الراتب الأساسي

    public function test_salary_set_from_user_form_with_effective_date(): void
    {
        $this->actingAs($this->boss)->put("/users/{$this->emp->id}", [
            'name' => $this->emp->name, 'username' => $this->emp->username, 'role_id' => $this->emp->role_id, 'status' => 'active',
            'work_type' => 'followup', 'base_salary' => '1200', 'salary_effective_from' => '2026-12-01',
        ])->assertSessionHasNoErrors();
        $this->assertSame(900.0, EmployeeSalary::at($this->emp->id, '2026-11-30'));
        $this->assertSame(1200.0, EmployeeSalary::at($this->emp->id, '2026-12-01'));
    }

    public function test_salary_visible_only_to_super_admin(): void
    {
        $this->actingAs($this->boss)->get("/users/{$this->emp->id}")->assertSee('الراتب الأساسي')->assertSee('900');
        $this->actingAs($this->makeUser(Role::ADMIN))->get("/users/{$this->emp->id}")->assertOk()->assertDontSee('الراتب الأساسي');
    }

    // ---------------- الكشف

    public function test_compute_month_deductions(): void
    {
        $this->standardMonth();
        $this->compute()->assertRedirect()->assertSessionHasNoErrors();

        $i = $this->item();
        $this->assertEquals(900, $i->base_salary);
        $this->assertEquals(30, $i->day_value);
        $this->assertEquals(900, $i->base_due);
        $this->assertSame(2, $i->absent_days);
        $this->assertSame(['2026-11-03', '2026-11-04'], $i->absent_dates);
        $this->assertEquals(60, $i->absence_deduction);
        $this->assertSame(1, $i->incomplete_days);
        $this->assertSame(['2026-11-05'], $i->incomplete_dates);
        $this->assertEquals(15, $i->incomplete_deduction);
        $this->assertSame(1, $i->late_count);
        $this->assertSame(120, $i->late_minutes);
        $this->assertEquals(825, $i->net);
        $this->assertSame('draft', PayrollMonth::sole()->status);

        $this->actingAs($this->boss)->get('/payroll?month=2026-11')->assertOk()->assertSee('سالم')->assertSee('825')->assertSee('2026-11-03');
    }

    public function test_excused_leave_holiday_and_waiver_not_deducted(): void
    {
        $this->fillNovember($this->emp, ['2026-11-03', '2026-11-04', '2026-11-10', '2026-11-11']);
        \App\Models\AttendanceOverride::create(['user_id' => $this->emp->id, 'date' => '2026-11-03', 'type' => 'excused']);
        \App\Models\Leave::create(['user_id' => $this->emp->id, 'from_date' => '2026-11-04', 'to_date' => '2026-11-04', 'type' => 'sick']);
        \App\Models\Holiday::create(['date' => '2026-11-10', 'name' => 'عطلة']);
        \App\Models\AttendanceWaiver::create(['from_date' => '2026-11-11', 'to_date' => '2026-11-11', 'cancel_late' => false, 'cancel_absence' => true]);
        $this->compute();
        $this->assertSame(0, $this->item()->absent_days);
        $this->assertEquals(900, $this->item()->net);
    }

    public function test_incomplete_percent_comes_from_settings(): void
    {
        $this->standardMonth();
        SystemSetting::where('key', 'payroll_incomplete_percent')->update(['value' => '100']);
        Cache::flush();
        $this->compute();
        $this->assertEquals(30, $this->item()->incomplete_deduction);
    }

    public function test_mid_month_hire_is_prorated_and_salary_taken_from_month_start(): void
    {
        $new = $this->makeUser(Role::EMPLOYEE, ['name' => 'هند', 'hired_at' => '2026-11-16', 'created_at' => '2026-11-16']);
        EmployeeSalary::create(['user_id' => $new->id, 'amount' => 600, 'effective_from' => '2026-11-16']);
        $this->fillNovember($new, [], '2026-11-16');
        EmployeeSalary::create(['user_id' => $this->emp->id, 'amount' => 1500, 'effective_from' => '2026-11-20']);   // زيادة منتصف الشهر ⇒ من الشهر التالي
        $this->fillNovember($this->emp);
        $this->compute();

        $h = $this->item($new);
        $this->assertSame(15, $h->paid_days);
        $this->assertEquals(300, $h->base_due);
        $this->assertSame(0, $h->absent_days);
        $this->assertEquals(300, $h->net);
        $this->assertEquals(900, $this->item()->base_salary);
    }

    public function test_employee_without_salary_is_listed_as_missing(): void
    {
        $this->makeUser(Role::EMPLOYEE, ['name' => 'بدون راتب', 'hired_at' => '2026-01-01']);
        $this->compute();
        $this->assertSame(1, PayrollItem::count());
        $this->actingAs($this->boss)->get('/payroll?month=2026-11')->assertSee('بدون راتب')->assertSee('لم يُحدَّد راتب');
    }

    public function test_adjustments_survive_recompute(): void
    {
        $this->standardMonth();
        $this->compute();
        $this->actingAs($this->boss)->post('/payroll/adjustments', ['month' => '2026-11', 'user_id' => $this->emp->id, 'kind' => 'addition', 'label' => 'مكافأة', 'amount' => '100'])->assertSessionHasNoErrors();
        $this->actingAs($this->boss)->post('/payroll/adjustments', ['month' => '2026-11', 'user_id' => $this->emp->id, 'kind' => 'deduction', 'label' => 'سلفة', 'amount' => '50.5'])->assertSessionHasNoErrors();
        $this->assertEquals(874.5, $this->item()->net);

        $this->compute();
        $this->assertEquals(874.5, $this->item()->net);
        $this->assertEquals(100, $this->item()->additions);
        $this->assertEquals(50.5, $this->item()->deductions);

        $adj = PayrollAdjustment::where('label', 'سلفة')->sole();
        $this->actingAs($this->boss)->delete("/payroll/adjustments/{$adj->id}")->assertRedirect();
        $this->assertEquals(925, $this->item()->net);
    }

    public function test_approve_locks_month_and_reopen_allows_recompute(): void
    {
        $this->standardMonth();
        $this->compute();
        $this->actingAs($this->boss)->post('/payroll/approve', ['month' => '2026-11'])->assertSessionHasNoErrors();
        $this->assertSame('approved', PayrollMonth::sole()->status);

        \App\Models\AttendanceOverride::create(['user_id' => $this->emp->id, 'date' => '2026-11-03', 'type' => 'excused']);
        $this->compute()->assertSessionHasErrors('payroll');
        $this->actingAs($this->boss)->post('/payroll/adjustments', ['month' => '2026-11', 'user_id' => $this->emp->id, 'kind' => 'addition', 'label' => 'x', 'amount' => '5'])
            ->assertSessionHasErrors('payroll');
        $this->assertEquals(825, $this->item()->net);

        $this->actingAs($this->boss)->post('/payroll/reopen', ['month' => '2026-11'])->assertSessionHasNoErrors();
        $this->compute()->assertSessionHasNoErrors();
        $this->assertEquals(855, $this->item()->net);
        $this->assertTrue(\App\Models\AuditLog::where('event', 'payroll_approved')->exists());
        $this->assertTrue(\App\Models\AuditLog::where('event', 'payroll_reopened')->exists());
    }

    public function test_current_month_cannot_be_approved(): void
    {
        $this->compute('2026-12');
        $this->actingAs($this->boss)->post('/payroll/approve', ['month' => '2026-12'])->assertSessionHasErrors('payroll');
        $this->assertSame('draft', PayrollMonth::sole()->status);
    }

    public function test_only_super_admin(): void
    {
        $admin = $this->makeUser(Role::ADMIN);
        $this->actingAs($admin)->get('/payroll')->assertForbidden();
        $this->actingAs($admin)->post('/payroll/compute', ['month' => '2026-11'])->assertForbidden();
        $this->actingAs($admin)->get('/payroll/export?month=2026-11')->assertForbidden();
        $this->actingAs($this->emp)->get('/payroll')->assertForbidden();
        $this->actingAs($this->boss)->get('/payroll')->assertOk();
    }

    public function test_export_slip_and_whatsapp(): void
    {
        $this->standardMonth();
        $this->compute();
        $x = $this->actingAs($this->boss)->get('/payroll/export?month=2026-11')->assertOk();
        $this->assertStringContainsString('spreadsheetml', $x->headers->get('Content-Type'));

        $i = $this->item();
        $this->actingAs($this->boss)->get("/payroll/items/{$i->id}/slip")->assertOk()
            ->assertSee('قسيمة راتب')->assertSee('سالم')->assertSee('825')->assertSee('2026-11-04')->assertSee('wa.me/218912345678', false);
        $this->actingAs($this->makeUser(Role::ADMIN))->get("/payroll/items/{$i->id}/slip")->assertForbidden();
    }

    public function test_salary_amounts_not_in_audit_log(): void
    {
        $this->actingAs($this->boss)->put("/users/{$this->emp->id}", [
            'name' => $this->emp->name, 'username' => $this->emp->username, 'role_id' => $this->emp->role_id, 'status' => 'active',
            'work_type' => 'followup', 'base_salary' => '4321', 'salary_effective_from' => '2026-12-01',
        ]);
        $this->assertTrue(\App\Models\AuditLog::where('event', 'salary_changed')->exists());
        foreach (\App\Models\AuditLog::all() as $log) {
            $this->assertStringNotContainsString('4321', json_encode([$log->old_values, $log->new_values, $log->description], JSON_UNESCAPED_UNICODE));
        }
    }
}
