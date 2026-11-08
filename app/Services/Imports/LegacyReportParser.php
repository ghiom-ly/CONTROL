<?php

namespace App\Services\Imports;

use App\Support\ArabicNormalizer;
use App\Support\CodeParser;

/**
 * يقرأ نص تقارير واتساب القديمة بقالب الشركة (ملحق أ في تصميم المرحلة 2).
 * متسامح مع تنسيق واتساب (* _ ~ •) والأرقام العربية والأسطر الفارغة والأقسام الناقصة.
 */
class LegacyReportParser
{
    private const SECTION_TITLES = [1 => 'ملخص', 2 => 'المحاولات', 3 => 'الأكواد', 4 => 'ملاحظات', 5 => 'المتابعة'];

    /** @return list<array> */
    public function parse(string $text): array
    {
        $blocks = [];
        $current = null;
        foreach (preg_split('/\R/u', $text) as $raw) {
            if (str_contains($this->clean($raw), 'ريبورت تاريخ')) {
                if ($current !== null) {
                    $blocks[] = $current;
                }
                $current = [];
            }
            if ($current !== null) {
                $current[] = $raw;
            }
        }
        if ($current !== null) {
            $blocks[] = $current;
        }

        return array_map(fn ($lines) => $this->parseBlock($lines), $blocks);
    }

    private function parseBlock(array $rawLines): array
    {
        $out = ['date' => null, 'employee_name' => null, 'stores' => [], 'attempt_times' => [],
            'customer_codes' => [], 'courier_codes' => [], 'notes' => null, 'errors' => [],
            'hash' => hash('sha256', trim(implode("\n", $rawLines))), 'text' => trim(implode("\n", $rawLines))];
        $notes = [];
        $section = 0;

        foreach ($rawLines as $raw) {
            $line = $this->clean($raw);
            if ($line === '') {
                continue;
            }
            if (($s = $this->sectionOf($line)) !== null) {
                $section = $s;

                continue;
            }
            [$key, $value] = $this->splitColon($line);

            if (str_contains($line, 'ريبورت تاريخ')) {
                $out['date'] = $this->date($value);

                continue;
            }
            if (str_contains($line, 'اسم الموظف')) {
                $out['employee_name'] = $value !== '' ? $value : null;

                continue;
            }

            if ($section === 3) {
                // القائمة «لاصقة»: الأكواد في الأسطر التالية تتبع آخر عنوان (زبون/مندوب)
                $bucket = $this->bucketOf($key) ?? $bucket ?? null;
                $this->addCodes($bucket, $this->bucketOf($key) ? $value : $line, $out);

                continue;
            }

            match ($section) {
                1 => $this->storeLine($key, $value, $out),
                2 => $this->timeLine($line, $out),
                4 => $notes[] = $line,
                default => null,
            };
        }

        $notes = array_values(array_filter($notes, fn ($n) => $n !== 'لا يوجد'));
        $out['notes'] = $notes ? implode("\n", $notes) : null;
        if ($out['date'] === null) {
            $out['errors'][] = 'تاريخ التقرير غير موجود أو غير صالح';
        }

        return $out;
    }

    private function clean(string $line): string
    {
        $line = preg_replace('/\p{Cf}+/u', '', $line);
        $line = str_replace(['*', '_', '~'], '', $line);
        $line = preg_replace('/^[\s•·▪●\-–—]+/u', '', $line);

        return trim(preg_replace('/\s+/u', ' ', ArabicNormalizer::digits($line)));
    }

    private function sectionOf(string $line): ?int
    {
        // «1) ملخص…» عنوان قسم؛ «1- متجر الأمل: 34» سطر متجر مرقّم وليس عنواناً
        if (preg_match('/^([1-5])\s*[\)\-.]\s*(.*)$/u', $line, $m) && str_contains($m[2], self::SECTION_TITLES[(int) $m[1]])) {
            return (int) $m[1];
        }
        foreach (self::SECTION_TITLES as $n => $title) {
            if (str_starts_with($line, $title) && ! preg_match('/[:：]\s*\S/u', $line)) {
                return $n;
            }
        }

        return null;
    }

    /** @return array{0: string, 1: string} */
    private function splitColon(string $line): array
    {
        $parts = preg_split('/\s*[:：]\s*/u', $line, 2);

        return [trim($parts[0]), trim($parts[1] ?? '')];
    }

    private function date(string $value): ?string
    {
        if (! preg_match('/(\d{1,4})[\-\/.](\d{1,2})[\-\/.](\d{1,4})/', $value, $m)) {
            return null;
        }
        [$y, $mo, $d] = strlen($m[1]) === 4 ? [(int) $m[1], (int) $m[2], (int) $m[3]] : [(int) $m[3], (int) $m[2], (int) $m[1]];
        if ($y < 100) {
            $y += 2000;
        }

        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }

    private function storeLine(string $key, string $value, array &$out): void
    {
        if ($key === '' || str_contains($key, 'كام متجر') || str_contains($key, 'إجمالي') || str_contains($key, 'اجمالي')) {
            return;
        }
        $key = preg_replace('/^\d+\s*[\-.\)]\s*/u', '', $key);   // ترقيم «1- »
        if (preg_match('/^(\d+)/', $value, $m)) {                // «15 طلب»
            $out['stores'][] = ['name' => $key, 'count' => (int) $m[1]];
        } elseif ($value === 'غير متوفر') {
            $out['stores'][] = ['name' => $key, 'count' => null];
        }
    }

    private function timeLine(string $line, array &$out): void
    {
        if (! preg_match('/(\d{1,2}):(\d{2})\s*(ص|م)?/u', $line, $m)) {
            return;
        }
        $h = (int) $m[1];
        $ampm = $m[3] ?? '';
        if ($ampm === 'م' && $h < 12) {
            $h += 12;
        } elseif ($ampm === 'ص' && $h === 12) {
            $h = 0;
        }
        if ($h <= 23 && (int) $m[2] <= 59) {
            $out['attempt_times'][] = sprintf('%02d:%s', $h, $m[2]);
        }
    }

    private function bucketOf(string $key): ?string
    {
        return str_contains($key, 'الزبون') ? 'customer_codes' : (str_contains($key, 'المندوب') ? 'courier_codes' : null);
    }

    private function addCodes(?string $bucket, string $value, array &$out): void
    {
        if ($bucket === null) {
            return;
        }
        $value = trim(str_replace('لا يوجد', '', $value));
        try {
            $out[$bucket] = array_values(array_unique(array_merge($out[$bucket], CodeParser::split($value))));
        } catch (\InvalidArgumentException $e) {
            $out['errors'][] = $e->getMessage();
        }
    }
}
