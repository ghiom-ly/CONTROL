<?php

namespace App\Reports;

use App\Reports\Definitions;

class ReportRegistry
{
    /** @return array<string, class-string<ManagementReport>> بالترتيب المعروض */
    public static function all(): array
    {
        return [
            'executive' => Definitions\ExecutiveSummaryReport::class,
            'stores' => Definitions\StorePerformanceReport::class,
            'declining' => Definitions\DecliningStoresReport::class,
            'open_codes' => Definitions\OpenCodesReport::class,
            'issues' => Definitions\IssuesReport::class,
            'contacts' => Definitions\ContactsReport::class,
            'confirmation' => Definitions\ConfirmationReport::class,
            'employee_monthly' => Definitions\EmployeeMonthlyReport::class,
            'attendance_monthly' => Definitions\AttendanceMonthlyReport::class,
            'store' => Definitions\StoreReport::class,
        ];
    }

    public static function make(string $key): ManagementReport
    {
        $class = static::all()[$key] ?? abort(404);

        return app($class);
    }
}
