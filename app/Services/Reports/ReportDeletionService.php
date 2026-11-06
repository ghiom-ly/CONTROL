<?php

namespace App\Services\Reports;

use App\Enums\ReportStatus;
use App\Models\DailyReport;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Analytics\AnalyticsService;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * حذف تقرير (ناعم، قابل للاستعادة) مع إزالة أثره من الشحنات والمقاييس والتحليلات والحضور،
 * والاستعادة تعيد الأثر (إعادة اعتماد المعتمد). الحذف النهائي يستخدمه حذف الموظف نهائياً.
 */
class ReportDeletionService
{
    public function __construct(
        private readonly ReportApprovalService $approvals,
        private readonly StoreMetricsBuilder $metrics,
        private readonly ShipmentSummaryRefresher $summaries,
    ) {}

    public function delete(DailyReport $report, User $actor): void
    {
        DB::transaction(function () use ($report, $actor) {
            $report = DailyReport::whereKey($report->id)->lockForUpdate()->firstOrFail();
            $keys = $this->approvals->affectedKeys($report);

            $report->followups()->update(['is_official' => false]);
            $report->delete();

            foreach ($keys['metric_keys'] as [$storeId, $date]) {
                $this->metrics->rebuild($storeId, $date);
            }
            foreach (Shipment::whereIn('id', $keys['shipment_ids'])->get() as $s) {
                $this->summaries->refresh($s);
                // شحنة سُجّلت لأول مرة من هذا التقرير ولم يعد لها متابعات رسمية ⇒ تُحذف ناعماً
                if ($s->first_report_id === $report->id && ! $s->followups()->where('is_official', true)->exists()) {
                    $s->delete();
                }
            }
            AuditLogger::log('report_deleted', 'حذف التقرير '.$report->report_number, $report, [], [], $actor->id);
            DB::afterCommit(fn () => AnalyticsService::flush());
        });
    }

    public function restore(DailyReport $report, User $actor): void
    {
        // شفت مفتوح واحد فقط: لا تُستعاد مسودة إذا بدأ الموظف شفتاً جديداً
        if ($report->status === ReportStatus::Draft && DailyReport::where('user_id', $report->user_id)->openDraft()->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages(['report' => 'لا يمكن استعادة هذه المسودة: الموظف عنده شفت مفتوح الآن.']);
        }
        DB::transaction(function () use ($report, $actor) {
            $report->restore();
            Shipment::onlyTrashed()->where('first_report_id', $report->id)->restore();
            if ($report->status === ReportStatus::Approved && ! $report->isConfirmation()) {
                $this->approvals->approve($report, $actor, null, [ReportStatus::Approved]);
            }
            AuditLogger::log('report_restored', 'استعادة التقرير '.$report->report_number, $report, [], [], $actor->id);
            DB::afterCommit(fn () => AnalyticsService::flush());
        });
    }

    /** حذف نهائي بعد إزالة الأثر (لحذف الموظف نهائياً) */
    public function forceDelete(DailyReport $report, User $actor): void
    {
        if (! $report->trashed()) {
            $this->delete($report, $actor);
        }
        DB::transaction(function () use ($report) {
            Shipment::withTrashed()->where('first_report_id', $report->id)->whereDoesntHave('followups', fn ($q) => $q->where('daily_report_id', '!=', $report->id))
                ->get()->each->forceDelete();
            DailyReport::withTrashed()->whereKey($report->id)->first()?->forceDelete();
        });
    }
}
