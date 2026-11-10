<?php

namespace App\Services;

use App\Models\EmployeeSalary;
use App\Models\PayrollAdjustment;
use App\Models\PayrollItem;
use App\Models\PayrollMonth;
use App\Models\SystemSetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * كشف الرواتب الشهري:
 *  قيمة اليوم = الراتب ÷ 30؛ الشهر الكامل = الراتب كاملاً، والمعيَّن خلال الشهر يأخذ أيامه من تاريخ التعيين.
 *  الخصم: أيام «غائب» × قيمة اليوم + أيام الشفت غير المكتمل × قيمة اليوم × النسبة من الإعدادات.
 *  التأخير والتقارير للعرض فقط. الإضافات/الخصومات اليدوية تبقى عند إعادة الحساب.
 */
class PayrollService
{
    public const DAYS = 30;

    public function __construct(private readonly AttendanceService $attendance) {}

    public static function run(CarbonImmutable $month): ?PayrollMonth
    {
        return PayrollMonth::whereDate('month', $month->startOfMonth()->toDateString())->first();
    }

    /** الموظفون المشمولون بالشهر: نشطون بدور موظف، تعيّنوا قبل نهاية الشهر */
    public function employeesFor(CarbonImmutable $month): Collection
    {
        $end = $month->endOfMonth()->toDateString();

        return $this->attendance->employees()->get()
            ->filter(fn (User $u) => $u->hired_at === null || $u->hired_at->toDateString() <= $end)->values();
    }

    /** الراتب المعتمد للشهر: الساري أول الشهر (أو يوم التعيين إن كان لاحقاً) */
    public static function salaryFor(User $u, CarbonImmutable $month): ?float
    {
        $start = $month->startOfMonth();
        $from = $u->hired_at && $u->hired_at->greaterThan($start) ? $u->hired_at->toDateString() : $start->toDateString();

        return EmployeeSalary::at($u->id, $from);
    }

    /** @return Collection<int, User> موظفون بلا راتب محدد */
    public function missingSalary(CarbonImmutable $month): Collection
    {
        return $this->employeesFor($month)->filter(fn (User $u) => self::salaryFor($u, $month) === null)->values();
    }

    public function compute(CarbonImmutable $month, User $actor): PayrollMonth
    {
        $month = $month->startOfMonth();

        return DB::transaction(function () use ($month, $actor) {
            $run = PayrollMonth::whereDate('month', $month->toDateString())->lockForUpdate()->first()
                ?? PayrollMonth::create(['month' => $month->toDateString(), 'status' => 'draft']);
            if ($run->isApproved()) {
                throw ValidationException::withMessages(['payroll' => 'كشف هذا الشهر معتمد — أعد فتحه أولاً لإعادة الحساب.']);
            }
            $percent = max(0, min(100, (int) SystemSetting::get('payroll_incomplete_percent', 50)));
            $kept = [];
            foreach ($this->employeesFor($month) as $u) {
                $salary = self::salaryFor($u, $month);
                if ($salary === null) {
                    continue;
                }
                $kept[] = $u->id;
                $data = $this->itemData($u, $month, $salary, $percent);
                $item = PayrollItem::updateOrCreate(['payroll_month_id' => $run->id, 'user_id' => $u->id], $data);
                $this->refreshNet($item);
            }
            PayrollItem::where('payroll_month_id', $run->id)->whereNotIn('user_id', $kept ?: [0])->delete();
            $run->update(['computed_at' => now(), 'computed_by' => $actor->id]);
            AuditLogger::log('payroll_computed', 'حساب كشف رواتب '.$month->format('Y-m'), $run);

            return $run;
        });
    }

    private function itemData(User $u, CarbonImmutable $month, float $salary, int $percent): array
    {
        $dayValue = round($salary / self::DAYS, 3);
        $paidDays = self::DAYS;
        if ($u->hired_at && $u->hired_at->greaterThan($month)) {
            $paidDays = min(self::DAYS, $month->daysInMonth - $u->hired_at->day + 1);
        }
        $baseDue = $paidDays >= self::DAYS ? $salary : round($dayValue * $paidDays, 3);

        $att = $this->attendance->month($u, $month);
        $days = collect($att['days']);
        $absent = $days->where('status', 'absent')->pluck('date')->values()->all();
        $incomplete = $days->where('status', 'incomplete')->pluck('date')->values()->all();
        $s = $att['summary'];

        return [
            'base_salary' => $salary, 'day_value' => $dayValue, 'paid_days' => $paidDays, 'base_due' => $baseDue,
            'absent_days' => count($absent), 'absent_dates' => $absent, 'absence_deduction' => round($dayValue * count($absent), 3),
            'incomplete_days' => count($incomplete), 'incomplete_dates' => $incomplete, 'incomplete_percent' => $percent,
            'incomplete_deduction' => round($dayValue * count($incomplete) * $percent / 100, 3),
            'late_count' => $s['late'], 'late_minutes' => $s['late_minutes'],
            'reports_approved' => $s['reports_approved'], 'reports_total' => $s['reports_total'],
            'net' => $baseDue,   // يُحدَّث فوراً في refreshNet بعد جمع البنود اليدوية
        ];
    }

    /** يعيد جمع الإضافات/الخصومات اليدوية والصافي */
    public function refreshNet(PayrollItem $item): void
    {
        $adj = PayrollAdjustment::where('payroll_month_id', $item->payroll_month_id)->where('user_id', $item->user_id)->get();
        $add = round((float) $adj->where('kind', 'addition')->sum('amount'), 3);
        $ded = round((float) $adj->where('kind', 'deduction')->sum('amount'), 3);
        $net = (float) $item->base_due - (float) $item->absence_deduction - (float) $item->incomplete_deduction + $add - $ded;
        $item->update(['additions' => $add, 'deductions' => $ded, 'net' => round($net, 3)]);
    }

    public function approve(CarbonImmutable $month, User $actor): void
    {
        DB::transaction(function () use ($month, $actor) {
            $run = PayrollMonth::whereDate('month', $month->startOfMonth()->toDateString())->lockForUpdate()->first();
            if (! $run || $run->isApproved()) {
                throw ValidationException::withMessages(['payroll' => $run ? 'الكشف معتمد بالفعل.' : 'احسب الكشف أولاً.']);
            }
            if ($month->startOfMonth()->greaterThanOrEqualTo(CarbonImmutable::today()->startOfMonth())) {
                throw ValidationException::withMessages(['payroll' => 'لا يُعتمد كشف شهر لم ينتهِ بعد.']);
            }
            $run->update(['status' => 'approved', 'approved_at' => now(), 'approved_by' => $actor->id]);
            AuditLogger::log('payroll_approved', 'اعتماد كشف رواتب '.$month->format('Y-m'), $run);
        });
    }

    public function reopen(CarbonImmutable $month, User $actor): void
    {
        DB::transaction(function () use ($month) {
            $run = PayrollMonth::whereDate('month', $month->startOfMonth()->toDateString())->lockForUpdate()->first();
            if (! $run?->isApproved()) {
                throw ValidationException::withMessages(['payroll' => 'الكشف غير معتمد.']);
            }
            $run->update(['status' => 'draft', 'approved_at' => null, 'approved_by' => null]);
            AuditLogger::log('payroll_reopened', 'إعادة فتح كشف رواتب '.$month->format('Y-m'), $run);
        });
    }
}
