<?php

namespace App\Services;

use App\Enums\FollowupResult;
use App\Models\ContactAttempt;
use App\Models\ShipmentFollowup;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * إحصائيات الاتصالات: المتابعات الرسمية من التقارير المعتمدة + السجلات اليدوية.
 * النسبة = تم الرد ÷ (تم الرد + لم يرد/مشغول/مغلق) — null عند مقام صفر.
 */
class ContactStatsService
{
    /** نتيجة المتابعة → [الطرف, تم الرد؟] أو null (متابعة بلا طرف محدد) */
    private const FOLLOWUP_MAP = [
        'customer_no_answer' => ['customer', false],
        'courier_no_answer' => ['courier', false],
        'courier_answered_still_delayed' => ['courier', true],
        'courier_promised_delivery' => ['courier', true],
    ];

    private const MANUAL_ANSWERED = ['answered'];

    private const MANUAL_NOT_ANSWERED = ['no_answer', 'busy', 'unreachable'];

    public function perEmployee(Carbon $from, Carbon $to): Collection
    {
        [$start, $end] = [$from->copy()->startOfDay(), $to->copy()->endOfDay()];
        $rows = [];
        $add = function (int $userId, ?string $party, ?bool $answered, int $n) use (&$rows) {
            $r = &$rows[$userId];
            $r ??= ['total' => 0, 'customer' => 0, 'customer_answered' => 0, 'courier' => 0, 'courier_answered' => 0];
            $r['total'] += $n;
            if ($party !== null && $answered !== null) {
                $r[$party] += $n;
                if ($answered) {
                    $r[$party.'_answered'] += $n;
                }
            }
        };

        ShipmentFollowup::query()->where('is_official', true)->whereBetween('followup_at', [$start, $end])
            ->selectRaw('user_id, result, COUNT(*) n')->groupBy('user_id', 'result')->get()
            ->each(function ($r) use ($add) {
                $result = $r->result instanceof FollowupResult ? $r->result->value : (string) $r->result;
                [$party, $answered] = self::FOLLOWUP_MAP[$result] ?? [null, null];
                $add((int) $r->user_id, $party, $answered, (int) $r->n);
            });

        ContactAttempt::query()->whereIn('party_type', ['customer', 'courier'])->whereBetween('contacted_at', [$start, $end])
            ->selectRaw('user_id, party_type, result, COUNT(*) n')->groupBy('user_id', 'party_type', 'result')->get()
            ->each(function ($r) use ($add) {
                $result = $r->result->value;
                $answered = in_array($result, self::MANUAL_ANSWERED, true) ? true
                    : (in_array($result, self::MANUAL_NOT_ANSWERED, true) ? false : null);
                $add((int) $r->user_id, $r->party_type->value, $answered, (int) $r->n);
            });

        $users = User::whereIn('id', array_keys($rows))->get(['id', 'name'])->keyBy('id');

        return collect($rows)->map(fn ($r, $id) => ['user' => $users[$id]] + $r + [
            'customer_rate' => self::rate($r['customer_answered'], $r['customer']),
            'courier_rate' => self::rate($r['courier_answered'], $r['courier']),
        ])->filter(fn ($r) => $r['user'] !== null)->sortByDesc('total')->values();
    }

    public function perStore(Carbon $from, Carbon $to): Collection
    {
        $rows = ContactAttempt::query()->where('party_type', 'store')
            ->whereBetween('contacted_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->selectRaw('store_id, COUNT(*) n, MAX(contacted_at) last_at')->groupBy('store_id')->get();
        $stores = Store::withTrashed()->whereIn('id', $rows->pluck('store_id'))->get(['id', 'name', 'code'])->keyBy('id');

        return $rows->filter(fn ($r) => isset($stores[$r->store_id]))
            ->map(fn ($r) => ['store' => $stores[$r->store_id], 'count' => (int) $r->n, 'last_at' => Carbon::parse($r->last_at)])
            ->sortByDesc('count')->values();
    }

    private static function rate(int $answered, int $total): ?float
    {
        return $total > 0 ? round($answered * 100 / $total, 2) : null;
    }
}
