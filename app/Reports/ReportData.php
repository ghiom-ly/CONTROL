<?php

namespace App\Reports;

/** محتوى تقرير جاهز للعرض بأي صيغة */
final class ReportData
{
    /**
     * @param  array<string, scalar|null>  $summary
     * @param  list<array{title: string, headers: list<string>, rows: list<list<scalar|null>>, landscape?: bool}>  $sections
     */
    public function __construct(
        public readonly string $title,
        public readonly string $subtitle,
        public readonly array $summary = [],
        public readonly array $sections = [],
        public readonly bool $internal = true,
    ) {}

    public function landscape(): bool
    {
        return collect($this->sections)->contains(fn ($s) => $s['landscape'] ?? false);
    }
}
