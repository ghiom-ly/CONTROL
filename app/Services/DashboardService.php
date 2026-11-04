<?php

namespace App\Services;

use App\Enums\FollowupResult;
use App\Enums\IssueStatus;
use App\Enums\ReportStatus;
use App\Enums\StoreStatus;
use App\Models\DailyReport;
use App\Models\Issue;
use App\Models\ReportStore;
use App\Models\ShipmentFollowup;
use App\Models\Store;
use App\Models\User;
use App\Support\Period;

/**
 * مؤشرات لوحة التحكم — جميعها استعلامات حقيقية.
 * الإحصائيات الرسمية (الشحنات والمتابعات) من التقارير المعتمدة فقط.
 * الموظف بدون صلاحية reports.view_all يرى أرقام تقاريره فقط.
 */
class DashboardService
{
    public function summary(Period $period, User $user): array
    {
        $from = $period->from->toDateString();
        $to = $period->to->toDateString();
        $scopeAll = $user->hasPermission('reports.view_all');

        $stores = Store::query()->selectRaw('status, COUNT(*) c')->groupBy('status')->pluck('c', 'status');

        $reports = DailyReport::query()
            ->when(! $scopeAll, fn ($q) => $q->where('user_id', $user->id))
            ->whereBetween('work_date', [$from, $to])
            ->selectRaw('status, COUNT(*) c')->groupBy('status')->pluck('c', 'status');

        $shipments = ReportStore::query()
            ->whereHas('report', fn ($q) => $q->official()->whereBetween('work_date', [$from, $to])
                ->when(! $scopeAll, fn ($q) => $q->where('user_id', $user->id)))
            ->selectRaw('SUM(shipments_count) total, COUNT(shipments_count) known')->first();

        $followups = ShipmentFollowup::query()->official()
            ->whereBetween('followup_at', [$period->from, $period->to])
            ->when(! $scopeAll, fn ($q) => $q->where('user_id', $user->id))
            ->selectRaw('result, COUNT(DISTINCT shipment_id) c')->groupBy('result')->pluck('c', 'result');

        $issues = Issue::query()->selectRaw('status, COUNT(*) c')->groupBy('status')->pluck('c', 'status');
        $resolvedInPeriod = Issue::query()->whereIn('status', [IssueStatus::Resolved, IssueStatus::Closed])
            ->whereBetween('resolved_at', [$period->from, $period->to])->count();

        return [
            'scope_all' => $scopeAll,
            'stores_total' => (int) $stores->except(StoreStatus::Archived->value)->sum(),
            'stores_active' => (int) ($stores[StoreStatus::Active->value] ?? 0),
            'stores_stopped' => (int) ($stores[StoreStatus::Stopped->value] ?? 0),
            'stores_needs_followup' => (int) ($stores[StoreStatus::NeedsFollowup->value] ?? 0),
            'reports_total' => (int) $reports->sum(),
            'reports_pending' => (int) ($reports[ReportStatus::PendingReview->value] ?? 0),
            'reports_approved' => (int) ($reports[ReportStatus::Approved->value] ?? 0),
            'reports_rejected' => (int) ($reports[ReportStatus::Rejected->value] ?? 0),
            'shipments_reviewed' => ($shipments && $shipments->known > 0) ? (int) $shipments->total : null,
            'delivered_after_followup' => (int) ($followups[FollowupResult::DeliveredAfterFollowup->value] ?? 0),
            'customer_no_answer' => (int) ($followups[FollowupResult::CustomerNoAnswer->value] ?? 0),
            'courier_no_answer' => (int) ($followups[FollowupResult::CourierNoAnswer->value] ?? 0),
            'followups_total' => (int) $followups->sum(),
            'issues_open' => (int) (($issues[IssueStatus::New->value] ?? 0) + ($issues[IssueStatus::InProgress->value] ?? 0)),
            'issues_resolved' => $resolvedInPeriod,
            'users_active' => User::where('status', 'active')->count(),
        ];
    }
}
