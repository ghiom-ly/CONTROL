<?php

namespace App\Alerts\Rules;

use App\Alerts\AlertRule;
use App\Alerts\Finding;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** متجر نشط ظهر في آخر 90 يوماً ولم يظهر منذ days أيام */
class StoreStoppedReporting implements AlertRule
{
    public function key(): string
    {
        return 'store_stopped_reporting';
    }

    public function evaluate(array $params, CarbonImmutable $now): array
    {
        $days = (int) ($params['days'] ?? 3);
        $rows = DB::table('store_daily_metrics')->where('metric_date', '>=', $now->subDays(90)->toDateString())
            ->where('shipments_count', '>', 0)->selectRaw('store_id, MAX(metric_date) d')->groupBy('store_id')
            ->havingRaw('MAX(metric_date) < ?', [$now->subDays($days)->toDateString()])->get();
        $stores = Store::whereIn('id', $rows->pluck('store_id'))->where('status', 'active')->pluck('name', 'id');

        return $rows->filter(fn ($r) => isset($stores[$r->store_id]))->map(fn ($r) => new Finding(
            "store:{$r->store_id}:stopped", 'توقف '.$stores[$r->store_id].' عن الظهور في التقارير',
            'آخر ظهور: '.substr((string) $r->d, 0, 10), 'store', (int) $r->store_id, $stores[$r->store_id],
        ))->values()->all();
    }
}
