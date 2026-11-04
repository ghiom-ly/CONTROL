<?php

namespace Tests\Concerns;

use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/** إنشاء ملفات Excel/CSV حقيقية للاختبارات */
trait MakesSpreadsheets
{
    /** @param list<list<mixed>> $rows */
    protected function xlsx(array $rows, string $name = 'shift.xlsx'): UploadedFile
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray($rows, null, 'A1', true);
        $path = tempnam(sys_get_temp_dir(), 'nx').'.xlsx';
        IOFactory::createWriter($book, 'Xlsx')->save($path);

        return new UploadedFile($path, $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    /** @param list<list<mixed>> $rows */
    protected function csv(array $rows, string $name = 'shift.csv', bool $bom = true): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'nc').'.csv';
        $fh = fopen($path, 'w');
        if ($bom) {
            fwrite($fh, "\xEF\xBB\xBF");
        }
        foreach ($rows as $r) {
            fputcsv($fh, $r, ',', '"', '');
        }
        fclose($fh);

        return new UploadedFile($path, $name, 'text/csv', null, true);
    }
}
