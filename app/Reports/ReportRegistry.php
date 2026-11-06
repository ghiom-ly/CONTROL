<?php

namespace App\Reports;

class ReportRegistry
{
    /** @return array<string, class-string<ManagementReport>> */
    public static function all(): array
    {
        return [];
    }

    public static function make(string $key): ManagementReport
    {
        $class = static::all()[$key] ?? abort(404);

        return app($class);
    }
}
