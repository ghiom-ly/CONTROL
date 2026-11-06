<?php

namespace App\Reports\Definitions;

use App\Models\Store;
use App\Reports\ReportData;
use App\Reports\ReportParams;
use Illuminate\Support\Facades\DB;

class StorePerformanceReport extends BaseReport
{
    public function key(): string
    {
        return 'stores';
    }

    public function title(): string
    {
        return 'أداء كل المتاجر';
    }

    public function build(ReportParams $p): ReportData
    {
        $per = $p->period;
        [$pf, $pt, $f, $t] = [$per->prevFrom->toDateString(), $per->prevTo->toDateString(), $per->from->toDateString(), $per->to->toDateString()];

        $orders = DB::table('store_daily_metrics')->whereBetween('metric_date', [$pf, $t])
            ->selectRaw('store_id, SUM(CASE WHEN metric_date <= ? THEN COALESCE(shipments_count,0) ELSE 0 END) prev,
                SUM(CASE WHEN metric_date >= ? THEN COALESCE(shipments_count,0) ELSE 0 END) cur', [$pt, $f])
            ->groupBy('store_id')->get()->keyBy('store_id');
        $rates = DB::table('report_stores as rs')->join('daily_reports as r', 'r.id', '=', 'rs.daily_report_id')
            ->where('r.status', 'approved')->whereNull('r.deleted_at')->whereBetween('r.work_date', [$f, $t])
            ->selectRaw('rs.store_id, AVG(rs.start_rate) a')->groupBy('rs.store_id')->pluck('a', 'store_id');
        $open = DB::table('shipments')->whereNull('deleted_at')->whereIn('processing_status', ['open', 'in_progress'])
            ->selectRaw('store_id, COUNT(*) n')->groupBy('store_id')->pluck('n', 'store_id');
        $issues = DB::table('issues')->whereNull('deleted_at')->whereBetween('reported_at', [$per->from->startOfDay(), $per->to->endOfDay()])
            ->whereNotNull('store_id')->selectRaw('store_id, COUNT(*) n')->groupBy('store_id')->pluck('n', 'store_id');

        $ids = collect($orders->keys())->merge($rates->keys())->merge($issues->keys())->unique();
        $rows = Store::withTrashed()->whereIn('id', $ids)->orderBy('name')->get(['id', 'name', 'code'])->map(function ($s) use ($orders, $rates, $open, $issues) {
            $prev = (int) ($orders[$s->id]->prev ?? 0);
            $cur = (int) ($orders[$s->id]->cur ?? 0);

            return [$s->name, $s->code, $prev, $cur, $prev > 0 ? round(($cur - $prev) / $prev * 100, 1) : null,
                isset($rates[$s->id]) ? round((float) $rates[$s->id], 2).'%' : null, (int) ($open[$s->id] ?? 0), (int) ($issues[$s->id] ?? 0)];
        })->all();

        return new ReportData($this->title(), $this->periodLabel($p), ['عدد المتاجر' => count($rows)], [[
            'title' => 'المتاجر', 'landscape' => true,
            'headers' => ['المتجر', 'الكود', 'الطلبيات السابقة', 'الطلبيات الحالية', 'التغير %', 'متوسط نسبة التسليم', 'الأكواد المفتوحة الآن', 'المشكلات'],
            'rows' => $rows,
        ]]);
    }
}
