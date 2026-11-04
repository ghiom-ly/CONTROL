<?php

namespace App\Services;

use App\Enums\FollowupResult;
use App\Enums\ReportStatus;
use App\Models\ContactAttempt;
use App\Models\DailyReport;
use App\Models\Issue;
use App\Models\ReportStore;
use App\Models\ShipmentFollowup;
use App\Models\User;
use App\Support\Period;
use Carbon\CarbonPeriod;

/**
 * مؤشرات أداء الموظف — من التقارير المعتمدة فقط (الإحصائيات الرسمية).
 * القيم غير المتوفرة تُعاد null وليس صفراً.
 */
class EmployeePerformanceService
{
    public function forUser(User $user, Period $period): array
    {
        $approved = DailyReport::query()->where('user_id', $user->id)
            ->where('status', ReportStatus::Approved)
            ->whereBetween('work_date', [$period->from->toDateString(), $period->to->toDateString()]);

        $reportIds = (clone $approved)->pluck('id');

        $statusCounts = DailyReport::query()->where('user_id', $user->id)
            ->whereBetween('work_date', [$period->from->toDateString(), $period->to->toDateString()])
            ->selectRaw('status, COUNT(*) c')->groupBy('status')->pluck('c', 'status');

        $storeAgg = ReportStore::query()->whereIn('daily_report_id', $reportIds)
            ->selectRaw('COUNT(DISTINCT store_id) stores, SUM(shipments_count) shipments, COUNT(shipments_count) shipments_known')
            ->first();

        $followups = ShipmentFollowup::query()->official()->where('user_id', $user->id)
            ->whereBetween('followup_at', [$period->from, $period->to]);

        $workingDays = $this->workingDays($period);
        $daysWithReport = (clone $approved)->distinct()->count('work_date');

        return [
            'reports_total' => (int) $statusCounts->sum(),
            'reports_approved' => (int) ($statusCounts[ReportStatus::Approved->value] ?? 0),
            'reports_pending' => (int) ($statusCounts[ReportStatus::PendingReview->value] ?? 0),
            'reports_rejected' => (int) ($statusCounts[ReportStatus::Rejected->value] ?? 0),
            'reports_draft' => (int) ($statusCounts[ReportStatus::Draft->value] ?? 0),
            'stores_followed' => (int) ($storeAgg->stores ?? 0),
            // null إذا لم تُدخل أي أعداد شحنات في الفترة
            'shipments_reviewed' => ($storeAgg && $storeAgg->shipments_known > 0) ? (int) $storeAgg->shipments : null,
            'codes_followed' => (clone $followups)->distinct()->count('shipment_id'),
            'delivered_after_followup' => (clone $followups)->where('result', FollowupResult::DeliveredAfterFollowup)->distinct()->count('shipment_id'),
            'contact_attempts' => ContactAttempt::where('user_id', $user->id)->whereBetween('contacted_at', [$period->from, $period->to])->count(),
            'issues_reported' => Issue::where('reported_by', $user->id)->whereBetween('reported_at', [$period->from, $period->to])->count(),
            'working_days' => $workingDays,
            'days_with_report' => $daysWithReport,
            'completion_rate' => $workingDays > 0 ? round($daysWithReport / $workingDays * 100, 1) : null,
            'late_reports' => $this->lateReports($user, $period),
        ];
    }

    /** أيام العمل حتى اليوم (باستثناء الجمعة) ومن تاريخ التعيين */
    private function workingDays(Period $period): int
    {
        $to = $period->to->copy()->min(now()->endOfDay());
        if ($to->lt($period->from)) {
            return 0;
        }
        $count = 0;
        foreach (CarbonPeriod::create($period->from->copy()->startOfDay(), $to->copy()->startOfDay()) as $day) {
            if (! $day->isFriday()) {
                $count++;
            }
        }

        return $count;
    }

    /** التقارير المقدمة بعد الموعد المحدد في الإعدادات */
    private function lateReports(User $user, Period $period): int
    {
        $deadline = \App\Models\SystemSetting::get('report_submission_deadline', '18:00');

        return DailyReport::query()->where('user_id', $user->id)
            ->whereNotNull('submitted_at')
            ->whereBetween('work_date', [$period->from->toDateString(), $period->to->toDateString()])
            ->whereRaw('submitted_at > TIMESTAMP(work_date, ?)', [$deadline.':00'])
            ->count();
    }
}
