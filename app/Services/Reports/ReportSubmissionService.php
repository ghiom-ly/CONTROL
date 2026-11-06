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
    public function submit(DailyReport $report, ?float $endRate, User $actor): array
    {
        return DB::transaction(function () use ($report, $endRate, $actor) {
            $report = DailyReport::whereKey($report->id)->lockForUpdate()->firstOrFail();
            $report->load('stores');

            if ($report->isConfirmation()) {
                return $this->submitConfirmation($report, $actor);
            }

            if ($report->stores->isEmpty()) {
                throw ValidationException::withMessages(['stores' => 'أضف متجراً واحداً على الأقل قبل الإرسال.']);
            }

            if ($report->status === ReportStatus::Rejected) {
                // النسخة المرفوضة حُفظت عند أول تعديل عليها (أو تُحفظ الآن إن لم يُعدَّل شيء)
                $this->revisions->snapshotOnce($report, 'إعادة تقديم بعد الإرجاع', $actor);
                $report->version++;
                $report->rejection_reason = null;
            }

            $known = $report->stores->pluck('shipments_count')->filter(fn ($c) => $c !== null);

            $fileEnd = $report->file_values['end_rate'] ?? null;
            $sources = $report->value_sources ?? [];
            $sources['end_delivery_rate'] = ($fileEnd !== null && $endRate !== null && round((float) $fileEnd, 2) === round($endRate, 2)) ? 'file' : 'manual';

            $report->fill([
                'value_sources' => $sources,
                'end_delivery_rate' => $endRate,
                'submitted_at' => now(),
                // الانصراف = أول إرسال؛ لا يتغير بإعادة التقديم بعد الإرجاع
                'first_submitted_at' => $report->first_submitted_at ?? now(),
                'status' => ReportStatus::PendingReview,
                'total_shipments' => $known->isEmpty() ? null : (int) $known->sum(),
                'contact_attempts_count' => count($report->attempt_times ?? []),
                'updated_by' => $actor->id,
            ])->save();

            // ملفات رُفعت ولم تُطبَّق لا تبقى معلّقة على تقرير أُرسل
            \App\Models\ImportBatch::where('daily_report_id', $report->id)
                ->where('status', \App\Enums\ImportStatus::Uploaded)->update(['status' => \App\Enums\ImportStatus::Cancelled]);

            $warnings = [];
            if (in_array('courier_without_screens', $report->warningFlags(), true)) {
                $warnings[] = 'قائمة المندوب بدون سكرينات.';
            }

            return ['warnings' => $warnings];
        });
    }

    /** تقرير التأكيد: طلب واحد على الأقل، بلا نسبة تسليم نهائية */
    private function submitConfirmation(DailyReport $report, User $actor): array
    {
        $total = $report->confirmationOrders()->count();
        if ($total === 0) {
            throw ValidationException::withMessages(['orders' => 'أضف طلباً واحداً على الأقل قبل الإرسال.']);
        }
        if ($report->status === ReportStatus::Rejected) {
            $this->revisions->snapshotOnce($report, 'إعادة تقديم بعد الإرجاع', $actor);
            $report->version++;
            $report->rejection_reason = null;
        }
        $report->fill([
            'submitted_at' => now(),
            'first_submitted_at' => $report->first_submitted_at ?? now(),
            'status' => ReportStatus::PendingReview,
            'total_shipments' => $total,
            'contact_attempts_count' => count($report->attempt_times ?? []),
            'updated_by' => $actor->id,
        ])->save();

        return ['warnings' => []];
    }
}
