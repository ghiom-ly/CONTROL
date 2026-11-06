<?php

namespace App\Alerts\Rules;

use App\Alerts\AlertRule;
use App\Alerts\Finding;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** طلبيات متجر في آخر يوم له أقل من متوسطه في compare_days قبله بنسبة ≥ percent (≥ 3 أيام بيانات) */
class ShipmentsBelowAverage implements AlertRule
{
    public function key(): string
    {
        return 'shipments_below_average';
    }

    public function evaluate(array $params, CarbonImmutable $now): array
    {
        $percent = (float) ($params['percent'] ?? 30);
        $days = (int) ($params['compare_days'] ?? 14);
        $since = $now->subDays($days + 7)->toDateString();

        $last = DB::table('store_daily_metrics')->where('metric_date', '>=', $since)->where('metric_date', '<=', $now->toDateString())
            ->whereNotNull('shipments_count')->selectRaw('store_id, MAX(metric_date) d')->groupBy('store_id');

        $rows = DB::table('store_daily_metrics as m')->joinSub($last, 'l', 'l.store_id', '=', 'm.store_id')
            ->whereNotNull('m.shipments_count')
            ->whereRaw('m.metric_date <= l.d AND m.metric_date >= DATE_SUB(l.d, INTERVAL ? DAY)', [$days])
            ->selectRaw('m.store_id, l.d,
                SUM(CASE WHEN m.metric_date = l.d THEN m.shipments_count END) last_n,
                AVG(CASE WHEN m.metric_date < l.d THEN m.shipments_count END) avg_n,
                COUNT(CASE WHEN m.metric_date < l.d THEN 1 END) cnt')
            ->groupBy('m.store_id', 'l.d')->get()
            ->filter(fn ($r) => $r->cnt >= 3 && $r->avg_n > 0 && ($r->avg_n - $r->last_n) / $r->avg_n * 100 >= $percent);

        $stores = Store::whereIn('id', $rows->pluck('store_id'))->where('status', '!=', 'archived')->pluck('name', 'id');

        return $rows->filter(fn ($r) => isset($stores[$r->store_id]))->map(fn ($r) => new Finding(
            "store:{$r->store_id}:below", 'انخفاض شحنات '.$stores[$r->store_id].' بنسبة '.round(($r->avg_n - $r->last_n) / $r->avg_n * 100).'%',
            'آخر يوم ('.substr((string) $r->d, 0, 10).'): '.(int) $r->last_n.' — المتوسط: '.round($r->avg_n, 1), 'store', (int) $r->store_id, $stores[$r->store_id],
        ))->values()->all();
    }
}
