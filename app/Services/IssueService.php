<?php

namespace App\Services;

use App\Enums\IssueStatus;
use App\Models\DailyReport;
use App\Models\Issue;
use App\Models\IssueUpdate;
use App\Models\SystemSetting;
use App\Models\User;
use App\Notifications\IssueNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class IssueService
{
    /** إنشاء مشكلة (يدوياً أو من تقرير معتمد) مع المهلة وتنبيه المسؤولين. نفس المفتاح من نفس التقرير لا يتكرر. */
    public function create(array $data, User $reporter, ?DailyReport $report = null, ?string $sourceKey = null): Issue
    {
        $issue = DB::transaction(function () use ($data, $reporter, $report, $sourceKey) {
            $values = $data + [
                'reported_by' => $reporter->id, 'reported_at' => now(), 'status' => IssueStatus::New,
                'due_at' => now()->addHours((int) SystemSetting::get('issue_resolution_sla_hours', 48)),
            ];

            return $report
                ? Issue::firstOrCreate(['daily_report_id' => $report->id, 'source_key' => $sourceKey], $values)
                : Issue::create($values);
        });

        if ($issue->wasRecentlyCreated) {
            DB::afterCommit(fn () => Notification::send(
                self::managers()->reject(fn (User $u) => $u->id === auth()->id()),
                new IssueNotification($issue, 'created'),
            ));
        }

        return $issue;
    }

    /** تحديث: تعليق/حالة/أولوية/إسناد/إجراء — يُسجَّل في سجل التحديثات */
    public function update(Issue $issue, array $data, User $actor): IssueUpdate
    {
        return DB::transaction(function () use ($issue, $data, $actor) {
            $issue = Issue::whereKey($issue->id)->lockForUpdate()->firstOrFail();
            $oldStatus = $issue->status;
            $oldAssignee = $issue->assigned_to;

            $status = isset($data['status']) ? IssueStatus::from($data['status']) : $oldStatus;
            $fill = array_intersect_key($data, array_flip(['priority', 'action_taken']));
            if (array_key_exists('assigned_to', $data)) {
                $fill['assigned_to'] = $data['assigned_to'] ?: null;
            }
            $fill['status'] = $status;
            if ($status !== $oldStatus) {
                $fill['resolved_at'] = match ($status) {
                    IssueStatus::Resolved => now(),
                    IssueStatus::Closed => $issue->resolved_at ?? now(),
                    default => null,
                };
                $fill['closed_at'] = $status === IssueStatus::Closed ? now() : null;
            }
            $issue->fill($fill)->save();

            $update = IssueUpdate::create([
                'issue_id' => $issue->id, 'user_id' => $actor->id,
                'old_status' => $status !== $oldStatus ? $oldStatus->value : null,
                'new_status' => $status !== $oldStatus ? $status->value : null,
                'old_assignee_id' => $issue->assigned_to !== $oldAssignee ? $oldAssignee : null,
                'new_assignee_id' => $issue->assigned_to !== $oldAssignee ? $issue->assigned_to : null,
                'comment' => $data['comment'] ?? null,
            ]);

            if ($issue->assigned_to && $issue->assigned_to !== $oldAssignee && $issue->assigned_to !== $actor->id) {
                DB::afterCommit(fn () => User::find($issue->assigned_to)?->notify(new IssueNotification($issue, 'assigned')));
            }

            return $update;
        });
    }

    /** المستخدمون النشطون الذين يديرون المشكلات */
    public static function managers()
    {
        return User::with('role')->where('status', 'active')->get()->filter(fn (User $u) => $u->hasPermission('issues.manage'))->values();
    }
}
