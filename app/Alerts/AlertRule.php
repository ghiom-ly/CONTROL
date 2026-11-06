<?php

namespace App\Alerts;

use Carbon\CarbonImmutable;

interface AlertRule
{
    public function key(): string;

    /** @return list<Finding> */
    public function evaluate(array $params, CarbonImmutable $now): array;
}
