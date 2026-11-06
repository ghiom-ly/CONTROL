<?php

namespace App\Reports\Renderers;

use App\Reports\ReportData;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** ورقة لكل قسم، RTL؛ الصف 1 العنوان والفترة، الصف 3 العناوين — بلا حد صفوف */
class ExcelRenderer
{
    public function download(ReportData $data, string $filename): StreamedResponse
    {
        @set_time_limit(180);
        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);
        $sections = $data->sections ?: [['title' => 'ملخص', 'headers' => ['البند', 'القيمة'], 'rows' => []]];
        if ($data->summary) {
            array_unshift($sections, ['title' => 'ملخص', 'headers' => ['البند', 'القيمة'],
                'rows' => collect($data->summary)->map(fn ($v, $k) => [$k, $v])->values()->all()]);
        }

        $used = [];
        foreach ($sections as $i => $s) {
            $sheet = $book->createSheet()->setRightToLeft(true);
            $sheet->setTitle($this->title($s['title'], $i, $used));
            $sheet->setCellValue('A1', $data->title.' — '.$data->subtitle);
            $sheet->getStyle('A1')->getFont()->setBold(true);
            $sheet->fromArray([$s['headers']], null, 'A3');
            $sheet->getStyle('3:3')->getFont()->setBold(true);
            if ($s['rows']) {
                // النص يُكتب نصاً صريحاً: لا صيغ (=…) من بيانات المستخدمين، ولا أكواد رقمية طويلة تتحول لأُس
                foreach ($s['rows'] as $ri => $row) {
                    foreach (array_values($row) as $ci => $v) {
                        if ($v === null) {
                            continue;
                        }
                        $ref = Coordinate::stringFromColumnIndex($ci + 1).($ri + 4);
                        is_int($v) || is_float($v)
                            ? $sheet->setCellValueExplicit($ref, $v, DataType::TYPE_NUMERIC)
                            : $sheet->setCellValueExplicit($ref, (string) $v, DataType::TYPE_STRING);
                    }
                }
            } else {
                $sheet->setCellValue('A4', 'لا توجد بيانات في هذه الفترة');
            }
        }
        $book->setActiveSheetIndex(0);

        return new StreamedResponse(function () use ($book) {
            @set_time_limit(180);
            IOFactory::createWriter($book, 'Xlsx')->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /** عنوان ورقة صالح: بلا \ / ? * [ ] : ، ≤ 31 حرفاً، وفريد */
    private function title(string $raw, int $i, array &$used): string
    {
        $t = trim(mb_substr(preg_replace('~[\\\\/?*\[\]:؟]~u', '', $raw), 0, 28)) ?: 'ورقة';
        $base = $t;
        $n = 2;
        while (in_array($t, $used, true)) {
            $t = mb_substr($base, 0, 26).' '.$n++;
        }
        $used[] = $t;

        return $t;
    }
}
