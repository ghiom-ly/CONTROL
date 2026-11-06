<?php

namespace App\Alerts\Rules;

use App\Alerts\AlertRule;
use App\Alerts\Finding;
use App\Models\DailyReport;
use App\Models\Leave;
use App\Services\AttendanceService;
use Carbon\CarbonImmutable;

/** بعد deadline: موظف نشط لم يرسل تقرير اليوم وليس في إجازة */
class EmployeeMissingReport implements AlertRule
{
    public function __construct(private readonly AttendanceService $attendance) {}

    public function key(): string
    {
        return 'employee_missing_report';
    }

    public function evaluate(array $params, CarbonImmutable $now): array
    {
        [$h, $m] = array_map('intval', explode(':', (string) ($params['deadline'] ?? '18:00')) + [0, 0]);
        if ($now->lt($now->setTime($h, $m))) {
            return [];
        }
        $date = $now->toDateString();
        $sent = DailyReport::whereDate('first_submitted_at', $date)->pluck('user_id')->flip();
        $onLeave = Leave::where('from_date', '<=', $date)->where('to_date', '>=', $date)->pluck('user_id')->flip();

        return $this->attendance->employees()->get(['id', 'name'])
            ->reject(fn ($u) => $sent->has($u->id) || $onLeave->has($u->id))
            ->map(fn ($u) => new Finding('user:'.$u->id.':'.$date, $u->name.' لم يرسل تقرير اليوم', null, 'user', $u->id, $u->name))
            ->values()->all();
    }
}
