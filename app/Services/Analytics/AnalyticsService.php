<?php

namespace App\Services\Analytics;

use App\Support\ComparePeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** كل الأرقام من التقارير المعتمدة والمتابعات الرسمية فقط — بتجميعات SQL */
class AnalyticsService
{
    private const LABELS = [
        'orders' => ['الطلبيات المُعمل عليها', 'count', false],
        'start_rate' => ['نسبة التسليم بداية الشفت', 'percent', false],
        'end_rate' => ['نسبة التسليم نهاية الشفت', 'percent', false],
        'improvement' => ['متوسط التحسن (نقاط)', 'points', false],
        'reports_approved' => ['التقارير المعتمدة', 'count', false],
        'followups' => ['المتابعات', 'count', false],
        'delivered_after_followup' => ['سُلّمت بعد المتابعة', 'count', false],
        'customer_no_answer' => ['لم يرد الزبون', 'count', true],
        'courier_no_answer' => ['لم يرد المندوب', 'count', true],
        'open_codes' => ['الأكواد المفتوحة', 'count', true],
        'issues_new' => ['مشكلات جديدة', 'count', true],
        'issues_resolved' => ['مشكلات محلولة', 'count', false],
    ];

    public function compare(ComparePeriod $p): array
    {
        $cur = $this->kpis($p->from, $p->to);
        $prev = $this->kpis($p->prevFrom, $p->prevTo);

        return collect(self::LABELS)->map(function ($meta, $key) use ($cur, $prev) {
            [$label, $unit, $lower] = $meta;
            $c = $cur[$key];
            $pv = $prev[$key];
            $diff = $c !== null && $pv !== null ? round($c - $pv, 2) : null;

            return [
                'key' => $key, 'label' => $label, 'unit' => $unit, 'lower_is_better' => $lower,
                'previous' => $pv, 'current' => $c, 'diff' => $diff,
                'percent' => $diff !== null && $pv ? round($diff / $pv * 100, 1) : null,
            ];
        })->values()->all();
    }

    /** @return array<string, ?float> */
    public function kpis(CarbonImmutable $from, CarbonImmutable $to): array
    {
        [$d1, $d2] = [$from->toDateString(), $to->toDateString()];
        [$t1, $t2] = [$from->startOfDay(), $to->endOfDay()];

        $orders = DB::table('store_daily_metrics')->whereBetween('metric_date', [$d1, $d2])->sum('shipments_count');

        $rates = $this->rates($d1, $d2);

        $f = DB::table('shipment_followups')->where('is_official', true)->whereBetween('followup_at', [$t1, $t2])
            ->selectRaw('result, COUNT(*) n')->groupBy('result')->pluck('n', 'result');

        return [
            'orders' => (float) $orders,
            'start_rate' => $rates['start'],
            'end_rate' => $rates['end'],
            'improvement' => $rates['start'] !== null && $rates['end'] !== null ? round($rates['end'] - $rates['start'], 2) : null,
            'reports_approved' => (float) DB::table('daily_reports')->where('status', 'approved')->whereBetween('work_date', [$d1, $d2])->whereNull('deleted_at')->count(),
            'followups' => (float) $f->sum(),
            'delivered_after_followup' => (float) ($f['delivered_after_followup'] ?? 0),
            'customer_no_answer' => (float) ($f['customer_no_answer'] ?? 0),
            'courier_no_answer' => (float) ($f['courier_no_answer'] ?? 0),
            'open_codes' => (float) DB::table('shipments')->whereNull('deleted_at')->whereIn('processing_status', ['open', 'in_progress'])
                ->where('registered_at', '<=', $d2)->count(),
            'issues_new' => (float) DB::table('issues')->whereNull('deleted_at')->whereBetween('reported_at', [$t1, $t2])->count(),
            'issues_resolved' => (float) DB::table('issues')->whereNull('deleted_at')->whereBetween('resolved_at', [$t1, $t2])->count(),
        ];
    }

    /** متوسط نسب البداية والنهاية للتقارير المعتمدة: مرجّح بـ start_total_orders حيث يتوفر، وإلا بسيط */
    private function rates(string $d1, string $d2): array
    {
        $r = DB::table('daily_reports')->where('status', 'approved')->whereNull('deleted_at')->whereBetween('work_date', [$d1, $d2])
            ->selectRaw('SUM(CASE WHEN start_total_orders > 0 AND start_delivery_rate IS NOT NULL THEN start_total_orders END) ws,
                SUM(CASE WHEN start_total_orders > 0 AND start_delivery_rate IS NOT NULL THEN start_total_orders * start_delivery_rate END) s_w,
                SUM(CASE WHEN start_total_orders > 0 AND end_delivery_rate IS NOT NULL THEN start_total_orders END) we,
                SUM(CASE WHEN start_total_orders > 0 AND end_delivery_rate IS NOT NULL THEN start_total_orders * end_delivery_rate END) e_w,
                AVG(start_delivery_rate) s_avg, AVG(end_delivery_rate) e_avg')->first();

        $pick = fn ($sum, $w, $avg) => $w > 0 ? round($sum / $w, 2) : ($avg !== null ? round((float) $avg, 2) : null);

        return ['start' => $pick($r->s_w, $r->ws, $r->s_avg), 'end' => $pick($r->e_w, $r->we, $r->e_avg)];
    }
}
