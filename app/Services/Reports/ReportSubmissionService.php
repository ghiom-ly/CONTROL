<?php

namespace App\Services\Reports;

use App\Enums\ReportStatus;
use App\Models\DailyReport;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReportSubmissionService
{
    public function __construct(private readonly ReportRevisionService $revisions) {}

    /** إرسال التقرير للمراجعة = نهاية الشفت */
    public function submit(DailyReport $report, float $endRate, User $actor): array
    {
        return DB::transaction(function () use ($report, $endRate, $actor) {
            $report = DailyReport::whereKey($report->id)->lockForUpdate()->firstOrFail();
            $report->load('stores');

            if ($report->stores->isEmpty()) {
                throw ValidationException::withMessages(['stores' => 'أضف متجراً واحداً على الأقل قبل الإرسال.']);
            }

            if ($report->status === ReportStatus::Rejected) {
                $this->revisions->snapshot($report, 'إعادة تقديم بعد الإرجاع', $actor);
                $report->version++;
                $report->rejection_reason = null;
            }

            $known = $report->stores->pluck('shipments_count')->filter(fn ($c) => $c !== null);

            $report->fill([
                'end_delivery_rate' => $endRate,
                'submitted_at' => now(),
                'status' => ReportStatus::PendingReview,
                'total_shipments' => $known->isEmpty() ? null : (int) $known->sum(),
                'contact_attempts_count' => count($report->attempt_times ?? []),
                'updated_by' => $actor->id,
            ])->save();

            $warnings = [];
            if (in_array('courier_without_screens', $report->warningFlags(), true)) {
                $warnings[] = 'قائمة المندوب بدون سكرينات.';
            }

            return ['warnings' => $warnings];
        });
    }
}
