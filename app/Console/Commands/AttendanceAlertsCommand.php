<?php

namespace App\Console\Commands;

use App\Models\AttendanceAlert;
use App\Models\SystemSetting;
use App\Models\User;
use App\Notifications\AttendanceAlertNotification;
use App\Services\AttendanceService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Notification;

/**
 * يُشغَّل كل 15 دقيقة: ينبّه المسؤولين مرة واحدة يومياً لكل حالة —
 * موظف لم يبدأ شفته بعد ساعة التأخير، أو بدأ ولم يرسل تقريره بعد آخر موعد.
 */
class AttendanceAlertsCommand extends Command
{
    protected $signature = 'nours:attendance-alerts';

    protected $description = 'تنبيه المسؤولين بالتأخير وعدم إرسال التقارير';

    public function handle(AttendanceService $attendance): int
    {
        $now = CarbonImmutable::now();
        $today = $now->toDateString();
        if ($today < (string) SystemSetting::get('attendance_start_date', $today)) {
            return self::SUCCESS;
        }
        $lateAt = CarbonImmutable::parse($today.' '.SystemSetting::get('attendance_late_after', '10:00'))
            ->addMinutes((int) SystemSetting::get('attendance_grace_minutes', 0));
        $deadline = CarbonImmutable::parse($today.' '.SystemSetting::get('report_submission_deadline', '18:00'));

        $managers = User::where('status', 'active')->with('role')->get()->filter(fn (User $u) => $u->hasPermission('reports.review'));
        if ($managers->isEmpty()) {
            return self::SUCCESS;
        }

        foreach ($attendance->employees()->get() as $emp) {
            // موظف لم يبدأ عمله بعد (تاريخ التعيين في المستقبل)
            if (($emp->hired_at ?? $emp->created_at)?->toDateString() > $today) {
                continue;
            }
            $state = $attendance->today($emp);
            if ($state['on_leave']) {
                continue;
            }
            $kind = match (true) {
                ! $state['started'] && $now->greaterThanOrEqualTo($lateAt) => 'not_started',
                $state['started'] && ! $state['submitted_all'] && $now->greaterThanOrEqualTo($deadline) => 'not_submitted',
                default => null,
            };
            if ($kind && $this->record($emp, $today, $kind)) {
                Notification::send($managers, new AttendanceAlertNotification($emp, $kind));
            }
        }

        return self::SUCCESS;
    }

    /** يسجّل التنبيه؛ false إن أُرسل سابقاً اليوم */
    private function record(User $emp, string $date, string $kind): bool
    {
        try {
            AttendanceAlert::create(['user_id' => $emp->id, 'alert_date' => $date, 'kind' => $kind]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }
}
