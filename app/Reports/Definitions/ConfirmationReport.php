<?php

namespace App\Reports\Definitions;

use App\Reports\ReportData;
use App\Reports\ReportParams;
use App\Services\Analytics\ConfirmationStatsService;

class ConfirmationReport extends BaseReport
{
    public function key(): string
    {
        return 'confirmation';
    }

    public function title(): string
    {
        return 'تقرير التأكيد';
    }

    public function build(ReportParams $p): ReportData
    {
        $stats = app(ConfirmationStatsService::class);
        $emp = $stats->perEmployee($p->period);
        $row = fn ($r, $name) => [$name, $r['total'], $r['confirmed'], $r['cancelled'], $r['no_answer'], self::pct($r['rate'])];
        $headers = ['الاسم', 'الإجمالي', 'المؤكدة', 'الملغاة', 'لا يرد', 'نسبة التأكيد'];
        $total = $emp->sum('total');

        return new ReportData($this->title(), $this->periodLabel($p), [
            'إجمالي الطلبيات' => $total, 'المؤكدة' => $emp->sum('confirmed'), 'الملغاة' => $emp->sum('cancelled'), 'لا يرد' => $emp->sum('no_answer'),
            'نسبة التأكيد' => $total > 0 ? round($emp->sum('confirmed') * 100 / $total, 2).'%' : null,
        ], [
            ['title' => 'الموظفات', 'headers' => $headers, 'rows' => $emp->map(fn ($r) => $row($r, $r['user']->name))->all()],
            ['title' => 'المتاجر', 'headers' => $headers, 'rows' => $stats->perStore($p->period, 500)->map(fn ($r) => $row($r, $r['store']->name))->all()],
            ['title' => 'أسباب الإلغاء', 'headers' => ['السبب', 'العدد'], 'rows' => collect($stats->reasons($p->period))->map(fn ($n, $r) => [$r, $n])->values()->all()],
        ]);
    }
}
