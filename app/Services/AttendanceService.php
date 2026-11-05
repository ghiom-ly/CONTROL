<?php

namespace App\Services;

use App\Enums\ReportStatus;
use App\Models\DailyReport;
use App\Models\Leave;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * الحضور والانصراف يُشتق من الشفتات (لا إدخال يدوي):
 * بدء الشفت = حضور، أول إرسال للتقرير = انصراف. الإجازات يسجّلها المسؤول.
 */
class AttendanceService
{
    public const STATUS_LABELS = [
        'present' => 'حاضر', 'late' => 'متأخر', 'incomplete' => 'شفت غير مكتمل', 'absent' => 'غائب',
        'leave' => 'إجازة', 'future' => '—', 'not_counted' => 'خارج الاحتساب',
    ];

    /** الموظفون الذين يُحسب حضورهم: دور «موظف»، نشطون */
    public function employees()
    {
        return User::query()->where('status', 'active')
            ->whereHas('role', fn ($r) => $r->where('name', Role::EMPLOYEE))->orderBy('name');
    }

    /** @return array{days: list<array>, summary: array} */
    public function month(User $user, CarbonImmutable $month): array
    {
        $from = $month->startOfMonth();
        $to = $month->endOfMonth();
        $today = CarbonImmutable::today()->toDateString();
        $lateAfter = (string) SystemSetting::get('attendance_late_after', '10:00');
        $grace = (int) SystemSetting::get('attendance_grace_minutes', 0);
        $countFrom = max(
            (string) SystemSetting::get('attendance_start_date', $from->toDateString()),
            ($user->hired_at ?? $user->created_at)?->toDateString() ?? '0000-00-00',
        );

        $reports = DailyReport::where('user_id', $user->id)
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
            ->get(['id', 'work_date', 'status', 'source', 'is_legacy', 'shift_started_at', 'first_submitted_at']);
        $byDay = $reports->groupBy(fn ($r) => $r->work_date->toDateString());
        $leaves = Leave::where('user_id', $user->id)->where('from_date', '<=', $to->toDateString())
            ->where('to_date', '>=', $from->toDateString())->get();

        $days = [];
        for ($d = $from; $d <= $to; $d = $d->addDay()) {
            $date = $d->toDateString();
            $days[] = $this->day($date, $byDay[$date] ?? collect(), $leaves, $today, $countFrom, $lateAfter, $grace);
        }

        return ['days' => $days, 'summary' => $this->summarize($days, $reports)];
    }

    /** @return Collection<int, array{user: User, summary: array}> */
    public function summaryForAll(CarbonImmutable $month): Collection
    {
        return $this->employees()->get()->map(fn (User $u) => ['user' => $u, 'summary' => $this->month($u, $month)['summary']]);
    }

    /** حالة اليوم الحالي (للتنبيهات) */
    public function today(User $user): array
    {
        $today = CarbonImmutable::today();
        $shifts = DailyReport::where('user_id', $user->id)->whereDate('work_date', $today)
            ->where('is_legacy', false)->whereNotNull('shift_started_at')->get(['first_submitted_at']);

        return [
            'started' => $shifts->isNotEmpty(),
            'submitted_all' => $shifts->isNotEmpty() && $shifts->every(fn ($s) => $s->first_submitted_at !== null),
            'on_leave' => Leave::where('user_id', $user->id)->whereDate('from_date', '<=', $today)->whereDate('to_date', '>=', $today)->exists(),
        ];
    }

    private function day(string $date, Collection $reports, Collection $leaves, string $today, string $countFrom, string $lateAfter, int $grace): array
    {
        $out = ['date' => $date, 'status' => null, 'in' => null, 'out' => null, 'minutes' => null,
            'late_minutes' => null, 'leave_type' => null, 'legacy' => false, 'incomplete' => false];

        $shifts = $reports->filter(fn ($r) => ! $r->is_legacy && $r->shift_started_at !== null);
        if ($shifts->isNotEmpty()) {
            $in = $shifts->min('shift_started_at');
            // الانصراف يُحسب فقط إذا أُرسل التقرير في نفس يوم الشفت
            $submitted = $shifts->filter(fn ($r) => $r->first_submitted_at !== null && $r->first_submitted_at->toDateString() === $date);
            $out['in'] = $in->format('H:i');
            $out['out'] = $submitted->max('first_submitted_at')?->format('H:i');
            $out['minutes'] = $submitted->isEmpty() ? null
                : (int) $submitted->sum(fn ($r) => max(0, $r->shift_started_at->diffInMinutes($r->first_submitted_at)));

            $ref = CarbonImmutable::parse("{$date} {$lateAfter}");
            if ($in->greaterThan($ref->addMinutes($grace))) {
                $out['late_minutes'] = (int) $ref->diffInMinutes($in);
            }
            $out['incomplete'] = $date < $today && $submitted->count() < $shifts->count();
            $out['status'] = $out['incomplete'] ? 'incomplete' : ($out['late_minutes'] ? 'late' : 'present');

            return $out;
        }

        if ($reports->contains(fn ($r) => $r->is_legacy)) {
            return array_merge($out, ['status' => 'present', 'legacy' => true]);
        }
        if ($date < $countFrom) {
            return array_merge($out, ['status' => 'not_counted']);
        }
        $leave = $leaves->first(fn (Leave $l) => $l->covers(CarbonImmutable::parse($date)));
        if ($leave) {
            return array_merge($out, ['status' => 'leave', 'leave_type' => Leave::TYPES[$leave->type] ?? $leave->type]);
        }

        return array_merge($out, ['status' => $date >= $today ? 'future' : 'absent']);
    }

    private function summarize(array $days, Collection $reports): array
    {
        $col = collect($days);
        $complete = $col->filter(fn ($d) => $d['minutes'] !== null && ! $d['incomplete']);

        return [
            'present' => $col->whereIn('status', ['present', 'late', 'incomplete'])->count(),
            'absent' => $col->where('status', 'absent')->count(),
            'leave' => $col->where('status', 'leave')->count(),
            'late' => $col->filter(fn ($d) => $d['late_minutes'])->count(),
            'late_minutes' => (int) $col->sum('late_minutes'),
            'incomplete' => $col->where('incomplete', true)->count(),
            'avg_minutes' => $complete->isEmpty() ? null : (int) round($complete->avg('minutes')),
            'reports_total' => $reports->count(),
            'reports_approved' => $reports->where('status', ReportStatus::Approved)->count(),
            'reports_rejected' => $reports->where('status', ReportStatus::Rejected)->count(),
            'reports_pending' => $reports->where('status', ReportStatus::PendingReview)->count(),
        ];
    }
}
