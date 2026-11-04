<?php

namespace App\Services;

use App\Enums\ReportStatus;
use App\Models\ReportStore;
use App\Models\Store;
use App\Models\StoreDailyMetric;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

/**
 * أرقام صفحة المتجر — من store_daily_metrics (المبني من التقارير المعتمدة فقط).
 */
class StoreInsightsService
{
    /** @return Collection<string, ?int> تاريخ => عدد الأوردرات (null = غير متوفر) */
    public function dailyOrders(Store $store, CarbonInterface $from, CarbonInterface $to): Collection
    {
        $rows = StoreDailyMetric::query()->where('store_id', $store->id)
            ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])
            ->get(['metric_date', 'shipments_count'])
            ->mapWithKeys(fn ($m) => [$m->metric_date->toDateString() => $m->shipments_count]);

        return collect(CarbonPeriod::create($from->copy()->startOfDay(), $to->copy()->startOfDay()))
            ->mapWithKeys(fn ($d) => [$d->toDateString() => isset($rows[$d->toDateString()]) ? (int) $rows[$d->toDateString()] : null]);
    }

    /** آخر 7 أيام (شاملة اليوم) مقابل السبعة السابقة */
    public function weekComparison(Store $store, CarbonInterface $today): array
    {
        $current = $this->sum($store, $today->copy()->subDays(6), $today);
        $previous = $this->sum($store, $today->copy()->subDays(13), $today->copy()->subDays(7));

        $diff = ($current !== null && $previous !== null) ? $current - $previous : null;
        $percent = ($diff !== null && $previous > 0) ? round($diff / $previous * 100, 1) : null;

        return ['current' => $current, 'previous' => $previous, 'diff' => $diff, 'percent' => $percent];
    }

    /** ملاحظات المتجر من التقارير المعتمدة */
    public function recentNotes(Store $store, int $limit = 10): Collection
    {
        return ReportStore::query()
            ->with('report:id,report_number,work_date,user_id', 'report.user:id,name')
            ->where('store_id', $store->id)->whereNotNull('notes')->where('notes', '!=', '')
            ->whereHas('report', fn ($q) => $q->where('status', ReportStatus::Approved))
            ->latest('id')->limit($limit)->get();
    }

    private function sum(Store $store, CarbonInterface $from, CarbonInterface $to): ?int
    {
        $row = StoreDailyMetric::query()->where('store_id', $store->id)
            ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('SUM(shipments_count) total, COUNT(shipments_count) known')->first();

        return ($row && $row->known > 0) ? (int) $row->total : null;
    }
}
