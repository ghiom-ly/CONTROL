<?php

namespace App\Services\Imports;

use App\Models\SystemSetting;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;

/**
 * يقرأ الورقة الأولى: صف العناوين = أول صف غير فارغ، وكل صف بعده مفهرس بالعناوين.
 * القيم نصوص مشذّبة، والفارغ null. التطبيع الرقمي مسؤولية الحاسب.
 */
class SpreadsheetReader
{
    /** @return array{headers: list<string>, rows: list<array{row: int, cells: array<string, ?string>}>} */
    public function read(string $absolutePath): array
    {
        try {
            $reader = IOFactory::createReaderForFile($absolutePath);
            if ($reader instanceof Csv) {
                // Excel العربي على ويندوز يحفظ CSV بترميز CP1256 وليس UTF-8 — نحوّله قبل القراءة
                $content = (string) file_get_contents($absolutePath);
                if (! mb_check_encoding($content, 'UTF-8') && ($utf8 = @iconv('CP1256', 'UTF-8//IGNORE', $content)) !== false) {
                    $absolutePath = tempnam(sys_get_temp_dir(), 'csv').'.csv';
                    file_put_contents($absolutePath, $utf8);
                }
                $reader->setInputEncoding('UTF-8');
            }
            $reader->setReadDataOnly(true);
            $sheet = $reader->load($absolutePath)->getSheet(0);
        } catch (\Throwable $e) {
            throw new ImportException('تعذّرت قراءة الملف — تأكد أنه ملف Excel أو CSV سليم.');
        }

        $max = (int) SystemSetting::get('import_max_rows', 5000);
        $headers = null;
        $rows = [];

        foreach ($sheet->getRowIterator() as $row) {
            $values = [];
            foreach ($row->getCellIterator() as $cell) {
                $values[] = $this->clean($cell->getCalculatedValue());
            }
            if (array_filter($values, fn ($v) => $v !== null) === []) {
                continue;
            }
            if ($headers === null) {
                $headers = $this->headers($values);

                continue;
            }
            if (count($rows) >= $max) {
                throw new ImportException("عدد الصفوف أكبر من الحد المسموح ({$max}).");
            }
            $cells = [];
            foreach ($headers as $i => $h) {
                $cells[$h] = $values[$i] ?? null;
            }
            $rows[] = ['row' => $row->getRowIndex(), 'cells' => $cells];
        }

        if ($headers === null) {
            throw new ImportException('الملف فارغ — لا يوجد صف عناوين.');
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    private function clean(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        if (is_float($v)) {
            $v = rtrim(rtrim(sprintf('%.10F', $v), '0'), '.');
        }
        $v = trim(str_replace("\u{FEFF}", '', (string) $v));

        return $v === '' ? null : $v;
    }

    /** @return list<string> */
    private function headers(array $values): array
    {
        $out = [];
        $seen = [];
        foreach ($values as $i => $v) {
            $name = $v ?? 'عمود '.($i + 1);
            $seen[$name] = ($seen[$name] ?? 0) + 1;
            $out[] = $seen[$name] > 1 ? "{$name} ({$seen[$name]})" : $name;
        }

        return $out;
    }
}
