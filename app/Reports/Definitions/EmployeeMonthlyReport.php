<?php

namespace App\Reports\Definitions;

use App\Reports\ReportData;
use App\Reports\ReportParams;
use App\Services\AttendanceService;
use Illuminate\Support\Facades\DB;

class EmployeeMonthlyReport extends BaseReport
{
    public function key(): string
    {
        return 'employee_monthly';
    }

    public function title(): string
    {
        return 'تقرير الموظف الشهري';
    }

    public function audience(): string
    {
        return 'employees';
    }

    public function needs(): array
    {
        return ['employee', 'month'];
    }

    public function build(ReportParams $p): ReportData
    {
        $u = $p->employee;
        $m = $p->month->startOfMonth();
        [$f, $t] = [$m->toDateString(), $m->endOfMonth()->toDateString()];

        $rep = DB::table('daily_reports')->where('user_id', $u->id)->where('status', 'approved')->whereNull('deleted_at')
            ->whereBetween('work_date', [$f, $t])->selectRaw('COUNT(*) n, AVG(end_delivery_rate - start_delivery_rate) imp')->first();
        $orders = (int) DB::table('report_stores as rs')->join('daily_reports as r', 'r.id', '=', 'rs.daily_report_id')
            ->where('r.user_id', $u->id)->where('r.status', 'approved')->whereNull('r.deleted_at')->whereBetween('r.work_date', [$f, $t])
            ->sum('rs.shipments_count');
        $c = $this->contacts->perEmployee(self::supportCarbon($m), self::supportCarbon($m->endOfMonth()))->firstWhere('user.id', $u->id);
        $att = $this->attendance->month($u, $m);
        $s = $att['summary'];

        return new ReportData($this->title().' — '.$u->name, 'الشهر: '.$m->format('Y/m'), [
            'التقارير المعتمدة' => (int) $rep->n,
            'الطلبيات' => $orders,
            'متوسط التحسن' => $rep->imp === null ? null : round((float) $rep->imp, 2),
            'نسبة رد الزبون' => self::pct($c['customer_rate'] ?? null),
            'نسبة رد المندوب' => self::pct($c['courier_rate'] ?? null),
            'أيام الحضور' => $s['present'],
            'الغياب' => $s['absent'],
            'مرات التأخير' => $s['late'],
            'الإجازات' => $s['leave'],
        ], [[
            'title' => 'أيام الشهر',
            'headers' => ['التاريخ', 'الحالة', 'الحضور', 'الانصراف', 'التأخير (دقيقة)'],
            'rows' => array_map(fn ($d) => [$d['date'], AttendanceService::STATUS_LABELS[$d['status']] ?? $d['status'], $d['in'], $d['out'], $d['late_minutes']], $att['days']),
        ]]);
    }
}
