<?php

namespace App\Reports\Definitions;

use App\Reports\ReportData;
use App\Reports\ReportParams;

class ExecutiveSummaryReport extends BaseReport
{
    public function key(): string
    {
        return 'executive';
    }

    public function title(): string
    {
        return 'الملخص التنفيذي';
    }

    public function build(ReportParams $p): ReportData
    {
        $cmp = $this->analytics->compare($p->period);
        $fmt = fn ($v, $unit) => $v === null ? null : ($unit === 'percent' ? $v.'%' : $v);
        $issues = $this->analytics->issueStats($p->period);
        $k = collect($cmp)->keyBy('key');   // بالمفتاح لا بالموضع

        return new ReportData(
            title: $this->title(),
            subtitle: $this->periodLabel($p).' مقابل '.$p->period->prevFrom->format('Y/m/d').' — '.$p->period->prevTo->format('Y/m/d'),
            summary: [
                'الطلبيات' => $k['orders']['current'], 'نسبة التسليم (نهاية)' => $fmt($k['end_rate']['current'], 'percent'),
                'التقارير المعتمدة' => $k['reports_approved']['current'], 'متوسط مدة حل المشكلات (ساعة)' => $issues['avg_resolution_hours'],
                'المشكلات المتأخرة الآن' => $issues['overdue_now'],
            ],
            sections: [
                ['title' => 'المقارنة بالفترة السابقة', 'headers' => ['المؤشر', 'السابق', 'الحالي', 'الفرق', 'النسبة'],
                    'rows' => array_map(fn ($r) => [$r['label'], $fmt($r['previous'], $r['unit']), $fmt($r['current'], $r['unit']), $r['diff'], self::pct($r['percent'])], $cmp)],
                ['title' => 'أكثر المتاجر تراجعاً', 'headers' => ['المتجر', 'السابق', 'الحالي', 'التغير'],
                    'rows' => $this->analytics->decliningStores($p->period)->map(fn ($r) => [$r['store']->name, $r['previous'], $r['current'], self::pct($r['percent'])])->all()],
                ['title' => 'المشكلات حسب النوع', 'headers' => ['النوع', 'العدد'],
                    'rows' => collect($issues['by_type'])->map(fn ($n, $t) => [$t, $n])->values()->all()],
            ],
        );
    }
}
