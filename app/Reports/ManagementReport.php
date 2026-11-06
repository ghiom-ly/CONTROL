<?php

namespace App\Reports;

interface ManagementReport
{
    public function key(): string;

    public function title(): string;

    /** management | employees | stores */
    public function audience(): string;

    /** @return list<string> من: period, month, store, employee */
    public function needs(): array;

    public function build(ReportParams $p): ReportData;
}
