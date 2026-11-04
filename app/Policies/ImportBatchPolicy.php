<?php

namespace App\Policies;

use App\Enums\ImportStatus;
use App\Models\ImportBatch;
use App\Models\User;

class ImportBatchPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission('imports.create') || $actor->hasPermission('imports.view_all');
    }

    public function view(User $actor, ImportBatch $batch): bool
    {
        if ($batch->uploaded_by === $actor->id || $actor->hasPermission('imports.view_all')) {
            return true;
        }
        $report = $batch->daily_report_id ? \App\Models\DailyReport::find($batch->daily_report_id) : null;

        return $report !== null && $actor->can('view', $report);
    }

    /** تعديل المطابقة/التطبيق/الإلغاء: صاحب الرفع وقبل التطبيق فقط */
    public function update(User $actor, ImportBatch $batch): bool
    {
        if ($batch->uploaded_by !== $actor->id || $batch->status !== ImportStatus::Uploaded) {
            return false;
        }
        $report = $batch->daily_report_id ? \App\Models\DailyReport::find($batch->daily_report_id) : null;

        return $report === null || self::reportOpenForFiles($report, $actor);
    }

    /** الملف يُطبَّق فقط على تقرير ما زال بيد الموظف (مسودة أو مُرجَع) */
    public static function reportOpenForFiles(\App\Models\DailyReport $report, User $actor): bool
    {
        return in_array($report->status, [\App\Enums\ReportStatus::Draft, \App\Enums\ReportStatus::Rejected], true)
            && $actor->can('update', $report);
    }
}
