<?php

namespace App\Policies;

use App\Enums\ReportStatus;
use App\Models\DailyReport;
use App\Models\SystemSetting;
use App\Models\User;

/**
 * ملاحظة: Gate::before يمنح المدير العام كل الصلاحيات (ما عدا ما يُفحص خارج الـ Policy مثل منع اعتماد تقريره).
 */
class DailyReportPolicy
{
    public function create(User $actor): bool
    {
        return $actor->hasPermission('reports.create');
    }

    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission('reports.create') || $actor->hasPermission('reports.view_all');
    }

    public function view(User $actor, DailyReport $report): bool
    {
        return $report->user_id === $actor->id || $actor->hasPermission('reports.view_all');
    }

    public function update(User $actor, DailyReport $report): bool
    {
        $owner = $report->user_id === $actor->id;

        return match ($report->status) {
            ReportStatus::Draft => $owner,
            ReportStatus::Rejected => ($owner && SystemSetting::get('employee_can_edit_rejected', true))
                || (! $owner && $actor->hasPermission('reports.review')),
            ReportStatus::PendingReview => ! $owner && $actor->hasPermission('reports.review'),
            ReportStatus::Approved => $actor->hasPermission('reports.admin_edit'),
        };
    }

    public function submit(User $actor, DailyReport $report): bool
    {
        return $report->user_id === $actor->id
            && in_array($report->status, [ReportStatus::Draft, ReportStatus::Rejected], true)
            && $this->update($actor, $report);
    }

    public function review(User $actor, DailyReport $report): bool
    {
        return $report->user_id !== $actor->id && $actor->hasPermission('reports.review');
    }
}
