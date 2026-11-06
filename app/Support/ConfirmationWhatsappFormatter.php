<?php

namespace App\Support;

use App\Models\DailyReport;

/** نص تقرير التأكيد بقالب الشركة كما يُرسل في واتساب */
class ConfirmationWhatsappFormatter
{
    public static function format(DailyReport $report): string
    {
        $report->loadMissing(['user:id,name', 'confirmationOrders.store:id,name']);
        $orders = $report->confirmationOrders;
        $by = fn (string $r) => $orders->filter(fn ($o) => $o->result->value === $r);
        [$confirmed, $cancelled, $noAnswer] = [$by('confirmed'), $by('cancelled'), $by('no_answer')];

        $lines = [
            'تقرير :['.$report->work_date->format('Y/n/j').']',
            '',
            'اسم الموظفه:'.($report->user?->name ?? ''),
            '',
            'ملخص حركة الأوردرات:',
            'إجمالي الأوردرات التي عملت عليها اليوم: ['.$orders->count().']',
            'الأوردرات المؤكدة : ['.$confirmed->count().']',
            'الأوردرات الملغاه: ['.$cancelled->count().']',
            'لا يرد:['.$noAnswer->count().']',
        ];

        $reasons = $cancelled->countBy('cancel_reason');
        if ($reasons->isNotEmpty()) {
            $lines[] = ' اسباب الالغاء ';
            $i = 1;
            foreach ($reasons as $reason => $n) {
                $lines[] = $i++.'- '.$reason.($n > 1 ? " ({$n})" : '');
            }
        }

        $group = function ($list, string $title, bool $withReason = false) use (&$lines) {
            $lines[] = '';
            $lines[] = $title;
            if ($list->isEmpty()) {
                $lines[] = 'لا يوجد';

                return;
            }
            foreach ($list->groupBy(fn ($o) => $o->store?->name ?? '—') as $store => $items) {
                $lines[] = '';
                $lines[] = $store.'('.$items->count().')';
                foreach ($items as $o) {
                    $lines[] = $o->code.($withReason && $o->cancel_reason ? ' — '.$o->cancel_reason : '');
                }
            }
        };
        $group($confirmed, 'المتاجر التي تم تاكيدها :');
        $group($cancelled, 'الطلبيات الملغاه:', true);
        $group($noAnswer, 'لا يرد:');

        $times = $report->attempt_times ?? [];
        $lines[] = '';
        $lines[] = '3️⃣ أوقات المحاولات:';
        $lines[] = 'إجمالي عدد المحاولات أجريتها اليوم: ['.count($times).']';
        $lines[] = 'مواعيد المحاولات: '.($times ? implode(' — ', $times) : 'لا يوجد');
        $lines[] = '';
        $lines[] = 'ملاحظة : '.($report->notes ?: 'لا يوجد');

        return implode("\n", $lines);
    }
}
