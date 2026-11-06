<?php

namespace App\Alerts\Rules;

use App\Alerts\AlertRule;
use App\Alerts\Finding;
use App\Models\DailyReport;
use Carbon\CarbonImmutable;

/** تقرير قيد المراجعة منذ أكثر من hours ساعة */
class ReportApprovalDelay implements AlertRule
{
    public function key(): string
    {
        return 'report_approval_delay';
    }

    public function evaluate(array $params, CarbonImmutable $now): array
    {
        return DailyReport::with('user:id,name')->where('status', 'pending_review')
            ->where('submitted_at', '<', $now->subHours((int) ($params['hours'] ?? 24)))->limit(500)->get()
            ->map(fn (DailyReport $r) => new Finding("report:{$r->id}", "تأخر اعتماد التقرير {$r->report_number}",
                'الموظف: '.($r->user?->name ?? '—').' — أُرسل '.$r->submitted_at->format('Y/m/d H:i'), 'report', $r->id, $r->report_number))
            ->all();
    }
}
