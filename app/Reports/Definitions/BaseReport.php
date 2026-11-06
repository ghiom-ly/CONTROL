<?php

namespace App\Reports\Definitions;

use App\Reports\ManagementReport;
use App\Reports\ReportParams;
use App\Services\Analytics\AnalyticsService;
use App\Services\AttendanceService;
use App\Services\ContactStatsService;

abstract class BaseReport implements ManagementReport
{
    public function __construct(
        protected readonly AnalyticsService $analytics,
        protected readonly ContactStatsService $contacts,
        protected readonly AttendanceService $attendance,
    ) {}

    public function audience(): string
    {
        return 'management';
    }

    public function needs(): array
    {
        return ['period'];
    }

    protected function periodLabel(ReportParams $p): string
    {
        return 'الفترة: '.$p->period->from->format('Y/m/d').' — '.$p->period->to->format('Y/m/d');
    }

    protected static function pct(?float $v): ?string
    {
        return $v === null ? null : $v.'%';
    }

    protected static function supportCarbon($c): \Illuminate\Support\Carbon
    {
        return \Illuminate\Support\Carbon::instance($c);
    }
}
