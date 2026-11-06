<?php

namespace App\Reports\Definitions;

use App\Reports\ReportData;
use App\Reports\ReportParams;

class ContactsReport extends BaseReport
{
    public function key(): string
    {
        return 'contacts';
    }

    public function title(): string
    {
        return 'الاتصالات';
    }

    public function build(ReportParams $p): ReportData
    {
        [$f, $t] = [self::supportCarbon($p->period->from), self::supportCarbon($p->period->to)];

        return new ReportData($this->title(), $this->periodLabel($p), [], [
            ['title' => 'إحصائيات الموظفين', 'headers' => ['الموظف', 'الإجمالي', 'الزبون', 'نسبة رد الزبون', 'المندوب', 'نسبة رد المندوب'],
                'rows' => $this->contacts->perEmployee($f, $t)->map(fn ($e) => [$e['user']->name, $e['total'], $e['customer'], self::pct($e['customer_rate']), $e['courier'], self::pct($e['courier_rate'])])->all()],
            ['title' => 'اتصالات الإدارة بالمتاجر', 'headers' => ['المتجر', 'عدد الاتصالات', 'آخر اتصال'],
                'rows' => $this->contacts->perStore($f, $t)->map(fn ($s) => [$s['store']->name, $s['count'], $s['last_at']->format('Y/m/d H:i')])->all()],
        ]);
    }
}
