<?php

namespace App\Reports\Definitions;

use App\Reports\ReportData;
use App\Reports\ReportParams;

class AttendanceMonthlyReport extends BaseReport
{
    public function key(): string
    {
        return 'attendance_monthly';
    }

    public function title(): string
    {
        return 'الحضور الشهري لكل الموظفين';
    }

    public function audience(): string
    {
        return 'employees';
    }

    public function needs(): array
    {
        return ['month'];
    }

    public function build(ReportParams $p): ReportData
    {
        $rows = $this->attendance->summaryForAll($p->month->startOfMonth())->map(fn ($r) => [
            $r['user']->name, $r['summary']['present'], $r['summary']['absent'], $r['summary']['leave'], $r['summary']['late'],
            $r['summary']['late_minutes'], $r['summary']['reports_approved'], $r['summary']['reports_rejected'],
        ])->all();

        return new ReportData($this->title(), 'الشهر: '.$p->month->format('Y/m'), ['عدد الموظفين' => count($rows)], [[
            'title' => 'الحضور',
            'landscape' => true,
            'headers' => ['الموظف', 'أيام الحضور', 'الغياب', 'الإجازات', 'مرات التأخير', 'دقائق التأخير', 'التقارير المعتمدة', 'المرفوضة'],
            'rows' => $rows,
        ]]);
    }
}
