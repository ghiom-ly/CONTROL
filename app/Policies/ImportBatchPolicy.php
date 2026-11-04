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
        return $batch->uploaded_by === $actor->id && $batch->status === ImportStatus::Uploaded;
    }
}
