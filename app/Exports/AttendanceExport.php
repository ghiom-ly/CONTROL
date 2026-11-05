<?php

namespace App\Exports;

use App\Services\AttendanceService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** تصدير الحضور الشهري: ورقة «ملخص» + ورقة لكل موظف */
class AttendanceExport
{
    public function __construct(private readonly AttendanceService $attendance) {}

    public function download(Collection $users, CarbonImmutable $month, string $filename): StreamedResponse
    {
        $book = new Spreadsheet;
        $summary = $book->getActiveSheet()->setTitle('ملخص')->setRightToLeft(true);
        $summary->fromArray([[
            'الموظف', 'أيام الحضور', 'الغياب', 'الإجازات', 'مرات التأخير', 'دقائق التأخير',
            'شفتات غير مكتملة', 'متوسط ساعات العمل', 'التقارير', 'المعتمدة', 'المرفوضة', 'قيد المراجعة',
        ]]);

        $r = 2;
        foreach ($users as $u) {
            $data = $this->attendance->month($u, $month);
            $s = $data['summary'];
            $summary->fromArray([[
                $u->name, $s['present'], $s['absent'], $s['leave'], $s['late'], $s['late_minutes'], $s['incomplete'],
                $s['avg_minutes'] === null ? null : round($s['avg_minutes'] / 60, 2),
                $s['reports_total'], $s['reports_approved'], $s['reports_rejected'], $s['reports_pending'],
            ]], null, 'A'.$r++);

            $sheet = $book->createSheet()->setTitle(mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/u', '', $u->name), 0, 31) ?: 'موظف '.$u->id)->setRightToLeft(true);
            $sheet->fromArray([['التاريخ', 'الحالة', 'الحضور', 'الانصراف', 'المدة (دقيقة)', 'التأخير (دقيقة)', 'الإجازة']]);
            $i = 2;
            foreach ($data['days'] as $d) {
                $sheet->fromArray([[
                    $d['date'], AttendanceService::STATUS_LABELS[$d['status']] ?? $d['status'], $d['in'], $d['out'],
                    $d['minutes'], $d['late_minutes'], $d['leave_type'],
                ]], null, 'A'.$i++);
            }
        }

        return new StreamedResponse(function () use ($book) {
            IOFactory::createWriter($book, 'Xlsx')->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="attendance-'.$month->format('Y-m').'.xlsx"; filename*=UTF-8\'\''.rawurlencode($filename),
        ]);
    }
}
