<?php

namespace App\Support;

use App\Models\DailyReport;

/**
 * نص التقرير بقالب الشركة المعتمد (ملحق أ في وثيقة التصميم) للصقه في واتساب.
 */
class ReportWhatsappFormatter
{
    private const ORDINALS = ['الأولى', 'الثانية', 'الثالثة', 'الرابعة', 'الخامسة', 'السادسة', 'السابعة', 'الثامنة', 'التاسعة', 'العاشرة'];

    public static function format(DailyReport $report): string
    {
        $report->loadMissing(['user:id,name', 'stores.store:id,name', 'followups']);
        $na = 'غير متوفر';
        $num = fn ($v) => $v === null ? $na : (string) $v;
        $rate = fn ($v) => $v === null ? $na : rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.').'%';

        $known = $report->stores->pluck('shipments_count')->filter(fn ($c) => $c !== null);
        $times = array_values($report->attempt_times ?? []);
        $codes = fn (string $result) => $report->followups->sortBy('id')->filter(fn ($f) => $f->result->value === $result)
            ->pluck('shipment_code')->implode(' ') ?: 'لا يوجد';

        $lines = [
            'ريبورت تاريخ: '.$report->work_date->format('Y-m-d'),
            'اسم الموظف: '.$report->user->name,
            '',
            'بداية الشفت: إجمالي الطلبيات '.$num($report->start_total_orders).' — نسبة التسليم '.$rate($report->start_delivery_rate).' ← '.$rate($report->end_delivery_rate),
            '',
            '1) ملخص حركة الأوردرات:',
            '- كام متجر تم العمل عليه: '.$report->stores->count(),
            '- إجمالي الأوردرات التي عملت عليها اليوم: '.($known->isEmpty() ? $na : $known->sum()),
        ];
        foreach ($report->stores->sortBy('id') as $rs) {
            $lines[] = '  - '.$rs->store->name.': '.$num($rs->shipments_count);
        }

        $lines[] = '';
        $lines[] = '2) أوقات المحاولات:';
        $lines[] = '- إجمالي عدد المحاولات الإجمالية التي أجريتها اليوم: '.count($times).' محاولة بالمجمل.';
        $lines[] = '- مواعيد المحاولات:';
        foreach ($times as $i => $t) {
            $lines[] = '  - المحاولة '.(self::ORDINALS[$i] ?? 'رقم '.($i + 1)).': الساعة '.$t;
        }

        $lines[] = '';
        $lines[] = '3) الأكواد غير المُجاب عليها:';
        $lines[] = '- أكواد لم يرد عليها الزبون (لتحويلها للمتاجر): '.$codes('customer_no_answer');
        $lines[] = '- أكواد لم يرد عليها المندوب أو لا تستجيب ولا تحل (مع إرسال السكرينات): '.$codes('courier_no_answer');

        $lines[] = '';
        $lines[] = '4) ملاحظات واجهت الموظف أو استفسارات:';
        $notes = array_filter([$report->notes, $report->inquiries], fn ($n) => filled($n));
        foreach ($notes ?: ['لا يوجد'] as $n) {
            $lines[] = '- '.trim($n);
        }

        return implode("\n", $lines);
    }
}
