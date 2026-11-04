<?php

namespace App\Support;

use App\Models\DailyReport;

/**
 * أرقام بداية الشفت للتقرير كله من أرقام المتاجر:
 * الإجمالي = مجموع الطلبيات، النسبة العامة = مرجّحة بعدد الطلبيات.
 */
class StartTotals
{
    /** @return array{total: ?int, rate: ?float} */
    public static function compute(iterable $rows): array
    {
        $total = null;
        $weighted = 0.0;
        $weight = 0;
        foreach ($rows as $r) {
            $orders = $r['start_orders'] ?? null;
            $rate = $r['start_rate'] ?? null;
            if ($orders !== null) {
                $total = ($total ?? 0) + (int) $orders;
            }
            if ($orders !== null && $rate !== null && (int) $orders > 0) {
                $weighted += (int) $orders * (float) $rate;
                $weight += (int) $orders;
            }
        }

        return ['total' => $total, 'rate' => $weight > 0 ? round($weighted / $weight, 2) : null];
    }

    /**
     * يحدّث أرقام بداية التقرير من متاجره — فقط إن كان لمتجر واحد على الأقل قيمة بداية
     * (التقارير القديمة بلا قيم لكل متجر تبقى كما هي).
     */
    public static function refresh(DailyReport $report): void
    {
        $stores = $report->stores()->get(['start_orders', 'start_rate', 'start_source']);
        $withValues = $stores->filter(fn ($s) => $s->start_orders !== null || $s->start_rate !== null);
        if ($withValues->isEmpty()) {
            return;
        }
        $t = self::compute($withValues->map(fn ($s) => ['start_orders' => $s->start_orders, 'start_rate' => $s->start_rate]));
        $source = $withValues->every(fn ($s) => $s->start_source === 'file') ? 'file' : 'manual';
        $report->forceFill([
            'start_total_orders' => $t['total'],
            'start_delivery_rate' => $t['rate'],
            'value_sources' => array_merge($report->value_sources ?? [], ['start_total_orders' => $source, 'start_delivery_rate' => $source]),
        ])->save();
    }
}
