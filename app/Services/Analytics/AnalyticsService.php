<?php

namespace App\Services\Analytics;

use App\Enums\IssueType;
use App\Enums\StoreStatus;
use App\Models\Issue;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\ContactStatsService;
use App\Support\ComparePeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** كل الأرقام من التقارير المعتمدة والمتابعات الرسمية فقط — بتجميعات SQL */
class AnalyticsService
{
    public function __construct(
        private readonly ContactStatsService $contacts,
        private readonly AttendanceService $attendance,
    ) {}

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

    /** بيانات الرسوم الستة — مفاتيح ثابتة، السلسلة الفارغة [] */
    public function charts(ComparePeriod $p): array
    {
        $days = $p->days();
        $dates = collect(range(0, $days - 1))->map(fn ($i) => $p->from->addDays($i)->toDateString());
        $prevDates = collect(range(0, $days - 1))->map(fn ($i) => $p->prevFrom->addDays($i)->toDateString());

        $rates = DB::table('daily_reports')->where('status', 'approved')->whereNull('deleted_at')
            ->whereBetween('work_date', [$dates->first(), $dates->last()])
            ->selectRaw('work_date d, AVG(start_delivery_rate) s, AVG(end_delivery_rate) e')->groupBy('work_date')->get()
            ->keyBy(fn ($r) => substr((string) $r->d, 0, 10));
        $orders = DB::table('store_daily_metrics')->whereBetween('metric_date', [$prevDates->first(), $dates->last()])
            ->selectRaw('metric_date d, SUM(COALESCE(shipments_count,0)) n')->groupBy('metric_date')->pluck('n', 'd')
            ->mapWithKeys(fn ($n, $d) => [substr((string) $d, 0, 10) => (int) $n]);
        $round = fn ($v) => $v === null ? null : round((float) $v, 2);

        $results = DB::table('shipment_followups')->where('is_official', true)
            ->whereBetween('followup_at', [$p->from->startOfDay(), $p->to->endOfDay()])
            ->selectRaw('result, COUNT(*) n')->groupBy('result')->orderByDesc('n')->pluck('n', 'result');
        $declining = $this->decliningStores($p);
        $employees = $this->employees($p)->filter(fn ($e) => $e['reports'] > 0)->values();
        $issues = $this->issueStats($p)['by_type'];

        return [
            'rate_trend' => [
                'labels' => $dates->map(fn ($d) => substr($d, 5))->all(),
                'start' => $dates->map(fn ($d) => $round($rates[$d]->s ?? null))->all(),
                'end' => $dates->map(fn ($d) => $round($rates[$d]->e ?? null))->all(),
            ],
            'orders' => [
                'labels' => $dates->map(fn ($d) => substr($d, 5))->all(),
                'current' => $dates->map(fn ($d) => $orders[$d] ?? 0)->all(),
                'previous' => $prevDates->map(fn ($d) => $orders[$d] ?? 0)->all(),
            ],
            'results' => [
                'labels' => $results->keys()->map(fn ($k) => \App\Enums\FollowupResult::from($k)->label())->all(),
                'values' => $results->values()->map(fn ($n) => (int) $n)->all(),
            ],
            'declining' => ['labels' => $declining->map(fn ($r) => $r['store']->name)->all(), 'values' => $declining->pluck('percent')->all()],
            'employees' => [
                'labels' => $employees->map(fn ($e) => $e['user']->name)->all(),
                'orders' => $employees->pluck('orders')->all(),
                'improvement' => $employees->pluck('improvement')->all(),
            ],
            'issues' => ['labels' => array_keys($issues), 'values' => array_values($issues)],
        ];
    }

    /** طلبيات كل متجر في الفترتين — استعلام واحد */
    private function storeOrders(ComparePeriod $p)
    {
        return DB::table('store_daily_metrics')
            ->whereBetween('metric_date', [$p->prevFrom->toDateString(), $p->to->toDateString()])
            ->selectRaw('store_id,
                SUM(CASE WHEN metric_date <= ? THEN COALESCE(shipments_count,0) ELSE 0 END) prev,
                SUM(CASE WHEN metric_date >= ? THEN COALESCE(shipments_count,0) ELSE 0 END) cur',
                [$p->prevTo->toDateString(), $p->from->toDateString()])
            ->groupBy('store_id');
    }

    public function decliningStores(ComparePeriod $p, int $limit = 10): Collection
    {
        $rows = DB::query()->fromSub($this->storeOrders($p), 't')
            ->where('prev', '>', 0)->whereColumn('cur', '<', 'prev')
            ->selectRaw('store_id, prev, cur, (cur - prev) / prev * 100 pct')->orderBy('pct')->limit($limit)->get();
        $stores = Store::whereIn('id', $rows->pluck('store_id'))->get(['id', 'name', 'code'])->keyBy('id');

        return $rows->filter(fn ($r) => isset($stores[$r->store_id]))->map(fn ($r) => [
            'store' => $stores[$r->store_id], 'previous' => (int) $r->prev, 'current' => (int) $r->cur, 'percent' => round((float) $r->pct, 1),
        ])->values();
    }

    public function rateDropStores(ComparePeriod $p, int $limit = 10): Collection
    {
        $agg = DB::table('report_stores as rs')->join('daily_reports as r', 'r.id', '=', 'rs.daily_report_id')
            ->where('r.status', 'approved')->whereNull('r.deleted_at')->whereNotNull('rs.start_rate')
            ->whereBetween('r.work_date', [$p->prevFrom->toDateString(), $p->to->toDateString()])
            ->selectRaw('rs.store_id,
                AVG(CASE WHEN r.work_date <= ? THEN rs.start_rate END) prev,
                AVG(CASE WHEN r.work_date >= ? THEN rs.start_rate END) cur', [$p->prevTo->toDateString(), $p->from->toDateString()])
            ->groupBy('rs.store_id');
        $rows = DB::query()->fromSub($agg, 't')->whereNotNull('prev')->whereNotNull('cur')->whereColumn('cur', '<', 'prev')
            ->orderByRaw('cur - prev')->limit($limit)->get();
        $stores = Store::whereIn('id', $rows->pluck('store_id'))->get(['id', 'name', 'code'])->keyBy('id');

        return $rows->filter(fn ($r) => isset($stores[$r->store_id]))->map(fn ($r) => [
            'store' => $stores[$r->store_id], 'previous' => round((float) $r->prev, 2), 'current' => round((float) $r->cur, 2),
            'diff' => round((float) $r->cur - (float) $r->prev, 2),
        ])->values();
    }

    public function stoppedStores(ComparePeriod $p): Collection
    {
        $ids = DB::query()->fromSub($this->storeOrders($p), 't')->where('prev', '>', 0)->where('cur', 0)->limit(50)->pluck('store_id');

        return Store::whereIn('id', $ids)->where('status', StoreStatus::Active)->orderBy('name')->get(['id', 'name', 'code']);
    }

    public function employees(ComparePeriod $p): Collection
    {
        [$d1, $d2] = [$p->from->toDateString(), $p->to->toDateString()];
        $users = User::whereHas('role', fn ($q) => $q->where('name', Role::EMPLOYEE))->where('status', 'active')->orderBy('name')->get(['id', 'name', 'role_id', 'hired_at', 'created_at']);

        $reports = DB::table('daily_reports')->where('status', 'approved')->whereNull('deleted_at')->whereBetween('work_date', [$d1, $d2])
            ->whereIn('user_id', $users->pluck('id'))
            ->selectRaw('user_id, COUNT(*) n, AVG(end_delivery_rate - start_delivery_rate) imp')->groupBy('user_id')->get()->keyBy('user_id');
        $orders = DB::table('report_stores as rs')->join('daily_reports as r', 'r.id', '=', 'rs.daily_report_id')
            ->where('r.status', 'approved')->whereNull('r.deleted_at')->whereBetween('r.work_date', [$d1, $d2])
            ->selectRaw('r.user_id, SUM(COALESCE(rs.shipments_count,0)) n')->groupBy('r.user_id')->pluck('n', 'user_id');
        $contacts = $this->contacts->perEmployee(\Illuminate\Support\Carbon::instance($p->from), \Illuminate\Support\Carbon::instance($p->to))
            ->keyBy(fn ($r) => $r['user']->id);

        return $users->map(function (User $u) use ($p, $reports, $orders, $contacts) {
            $att = $this->attendance->range($u, $p->from, $p->to);
            $imp = $reports[$u->id]->imp ?? null;

            return [
                'user' => $u, 'reports' => (int) ($reports[$u->id]->n ?? 0), 'orders' => (int) ($orders[$u->id] ?? 0),
                'improvement' => $imp === null ? null : round((float) $imp, 2),
                'customer_rate' => $contacts[$u->id]['customer_rate'] ?? null, 'courier_rate' => $contacts[$u->id]['courier_rate'] ?? null,
                'late' => $att['late'], 'absent' => $att['absent'],
            ];
        })->sortBy(fn ($r) => $r['improvement'] ?? PHP_FLOAT_MAX)->values();
    }

    public function issueStats(ComparePeriod $p): array
    {
        [$t1, $t2] = [$p->from->startOfDay(), $p->to->endOfDay()];
        $base = fn () => DB::table('issues')->whereNull('deleted_at')->whereBetween('reported_at', [$t1, $t2]);

        $byType = $base()->selectRaw('type, COUNT(*) n')->groupBy('type')->orderByDesc('n')->pluck('n', 'type')
            ->mapWithKeys(fn ($n, $t) => [IssueType::from($t)->label() => (int) $n])->all();
        $top = $base()->whereNotNull('store_id')->selectRaw('store_id, COUNT(*) n')->groupBy('store_id')->orderByDesc('n')->limit(10)->get();
        $stores = Store::withTrashed()->whereIn('id', $top->pluck('store_id'))->get(['id', 'name'])->keyBy('id');
        $avg = DB::table('issues')->whereNull('deleted_at')->whereBetween('resolved_at', [$t1, $t2])
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, reported_at, resolved_at)) m')->value('m');

        return [
            'by_type' => $byType,
            'top_stores' => $top->filter(fn ($r) => isset($stores[$r->store_id]))->map(fn ($r) => ['store' => $stores[$r->store_id], 'count' => (int) $r->n])->values(),
            'avg_resolution_hours' => $avg === null ? null : round($avg / 60, 1),
            'overdue_now' => Issue::overdue()->count(),
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
