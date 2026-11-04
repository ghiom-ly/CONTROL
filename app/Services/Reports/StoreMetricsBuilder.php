<?php

namespace App\Services\Reports;

use App\Enums\FollowupResult;
use App\Enums\ReportStatus;
use App\Models\DailyReport;
use App\Models\Issue;
use App\Models\ReportStore;
use App\Models\ShipmentFollowup;
use App\Models\StoreDailyMetric;

/**
 * يعيد حساب ملخص (متجر، يوم) من التقارير المعتمدة فقط — حذف ثم إعادة حساب، لا زيادة تراكمية.
 */
class StoreMetricsBuilder
{
    public function rebuild(int $storeId, string $date): void
    {
        $reportIds = DailyReport::query()->where('status', ReportStatus::Approved)->whereDate('work_date', $date)
            ->where(fn ($q) => $q->whereHas('stores', fn ($s) => $s->where('store_id', $storeId))
                ->orWhereHas('followups', fn ($f) => $f->where('store_id', $storeId)))
            ->pluck('id');

        if ($reportIds->isEmpty()) {
            StoreDailyMetric::where('store_id', $storeId)->whereDate('metric_date', $date)->delete();

            return;
        }

        $counts = ReportStore::whereIn('daily_report_id', $reportIds)->where('store_id', $storeId)
            ->selectRaw('SUM(shipments_count) total, COUNT(shipments_count) known')->first();

        $results = ShipmentFollowup::official()->whereIn('daily_report_id', $reportIds)->where('store_id', $storeId)
            ->selectRaw('result, COUNT(*) c')->groupBy('result')->pluck('c', 'result');

        StoreDailyMetric::updateOrCreate(
            ['store_id' => $storeId, 'metric_date' => $date],
            [
                'shipments_count' => $counts->known > 0 ? (int) $counts->total : null,
                'reports_count' => $reportIds->count(),
                'followups_count' => (int) $results->sum(),
                'delivered_after_followup' => (int) ($results[FollowupResult::DeliveredAfterFollowup->value] ?? 0),
                'customer_no_answer' => (int) ($results[FollowupResult::CustomerNoAnswer->value] ?? 0),
                'courier_no_answer' => (int) ($results[FollowupResult::CourierNoAnswer->value] ?? 0),
                'contact_attempts' => (int) DailyReport::whereIn('id', $reportIds)->sum('contact_attempts_count'),
                'issues_opened' => Issue::where('store_id', $storeId)->whereIn('daily_report_id', $reportIds)->count(),
            ],
        );
    }
}
