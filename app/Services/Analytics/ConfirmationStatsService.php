<?php

namespace App\Services\Analytics;

use App\Models\Store;
use App\Models\User;
use App\Support\ComparePeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** إحصائيات تأكيد الطلبيات من التقارير المعتمدة فقط — نسبة التأكيد = المؤكد ÷ الإجمالي */
class ConfirmationStatsService
{
    private function base(ComparePeriod $p)
    {
        return DB::table('confirmation_orders as o')->join('daily_reports as r', 'r.id', '=', 'o.daily_report_id')
            ->where('r.status', 'approved')->whereNull('r.deleted_at')
            ->whereBetween('r.work_date', [$p->from->toDateString(), $p->to->toDateString()]);
    }

    private const COUNTS = "COUNT(*) total,
        SUM(o.result = 'confirmed') confirmed, SUM(o.result = 'cancelled') cancelled, SUM(o.result = 'no_answer') no_answer";

    private static function row(object $r): array
    {
        $t = (int) $r->total;

        return ['total' => $t, 'confirmed' => (int) $r->confirmed, 'cancelled' => (int) $r->cancelled, 'no_answer' => (int) $r->no_answer,
            'rate' => $t > 0 ? round($r->confirmed * 100 / $t, 2) : null];
    }

    public function perEmployee(ComparePeriod $p): Collection
    {
        $rows = $this->base($p)->selectRaw('o.user_id, '.self::COUNTS)->groupBy('o.user_id')->get();
        $users = User::withTrashed()->whereIn('id', $rows->pluck('user_id'))->get(['id', 'name'])->keyBy('id');

        return $rows->filter(fn ($r) => isset($users[$r->user_id]))
            ->map(fn ($r) => ['user' => $users[$r->user_id]] + self::row($r))->sortByDesc('total')->values();
    }

    public function perStore(ComparePeriod $p, int $limit = 50): Collection
    {
        $rows = $this->base($p)->selectRaw('o.store_id, '.self::COUNTS)->groupBy('o.store_id')->orderByDesc('total')->limit($limit)->get();
        $stores = Store::withTrashed()->whereIn('id', $rows->pluck('store_id'))->get(['id', 'name'])->keyBy('id');

        return $rows->filter(fn ($r) => isset($stores[$r->store_id]))->map(fn ($r) => ['store' => $stores[$r->store_id]] + self::row($r))->values();
    }

    /** @return array<string, int> السبب ⇒ العدد، الأكثر أولاً */
    public function reasons(ComparePeriod $p): array
    {
        return $this->base($p)->where('o.result', 'cancelled')->whereNotNull('o.cancel_reason')
            ->selectRaw('o.cancel_reason reason, COUNT(*) n')->groupBy('o.cancel_reason')->orderByDesc('n')
            ->pluck('n', 'reason')->map(fn ($n) => (int) $n)->all();
    }
}
