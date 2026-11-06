<?php

namespace App\Reports;

use App\Models\Store;
use App\Models\User;
use App\Support\ComparePeriod;
use Carbon\CarbonImmutable;

final class ReportParams
{
    public function __construct(
        public readonly ComparePeriod $period,
        public readonly User $actor,
        public readonly ?CarbonImmutable $month = null,
        public readonly ?Store $store = null,
        public readonly ?User $employee = null,
    ) {}
}
