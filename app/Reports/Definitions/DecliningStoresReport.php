<?php

namespace App\Reports\Definitions;

use App\Reports\ReportData;
use App\Reports\ReportParams;

class DecliningStoresReport extends BaseReport
{
    public function key(): string
    {
        return 'declining';
    }

    public function title(): string
    {
        return 'المتاجر المتراجعة والمتوقفة';
    }

    public function build(ReportParams $p): ReportData
    {
        return new ReportData($this->title(), $this->periodLabel($p), [], [
            ['title' => 'نقص الطلبيات', 'headers' => ['المتجر', 'السابق', 'الحالي', 'التغير'],
                'rows' => $this->analytics->decliningStores($p->period, 100)->map(fn ($r) => [$r['store']->name, $r['previous'], $r['current'], self::pct($r['percent'])])->all()],
            ['title' => 'انخفاض نسبة التسليم', 'headers' => ['المتجر', 'السابقة', 'الحالية', 'الفرق'],
                'rows' => $this->analytics->rateDropStores($p->period, 100)->map(fn ($r) => [$r['store']->name, $r['previous'].'%', $r['current'].'%', $r['diff']])->all()],
            ['title' => 'متاجر توقفت', 'headers' => ['المتجر', 'الكود'],
                'rows' => $this->analytics->stoppedStores($p->period)->map(fn ($s) => [$s->name, $s->code])->all()],
        ]);
    }
}
