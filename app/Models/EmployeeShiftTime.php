<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * وقت بداية شفت الموظف بتاريخ سريان: يسري من effective_from حتى التغيير التالي.
 * start_time = null يعني الرجوع للوقت العام في الإعدادات. الأيام السابقة لا تتأثر بالتغيير.
 */
class EmployeeShiftTime extends Model
{
    use Auditable;

    protected $fillable = ['user_id', 'start_time', 'effective_from', 'created_by'];

    protected $casts = ['effective_from' => 'date'];

    /** @return array<int, list<array{from: string, time: ?string}>> user_id ⇒ السجل مرتباً تصاعدياً */
    public static function historyFor(Collection|array $userIds): array
    {
        $out = [];
        foreach (static::whereIn('user_id', $userIds)->orderBy('effective_from')->get(['user_id', 'start_time', 'effective_from']) as $r) {
            $out[$r->user_id][] = ['from' => $r->effective_from->toDateString(), 'time' => $r->start_time];
        }

        return $out;
    }

    /** وقت الشفت الساري في تاريخ معيّن من السجل، أو null (= الوقت العام) */
    public static function resolve(array $history, string $date): ?string
    {
        $time = null;
        foreach ($history as $h) {
            if ($h['from'] > $date) {
                break;
            }
            $time = $h['time'];
        }

        return $time;
    }

    public static function at(int $userId, string $date): ?string
    {
        return static::resolve(static::historyFor([$userId])[$userId] ?? [], $date);
    }

    public static function currentFor(int $userId): ?string
    {
        return static::at($userId, now()->toDateString());
    }

    /** يسجّل التغيير إن اختلف عن الساري في ذلك التاريخ؛ نفس التاريخ ⇒ يستبدل */
    public static function set(int $userId, ?string $time, string $from, ?int $actorId): void
    {
        $time = $time ?: null;
        $existing = static::where('user_id', $userId)->whereDate('effective_from', $from)->first();
        $before = static::resolve(array_values(array_filter(static::historyFor([$userId])[$userId] ?? [], fn ($h) => $h['from'] < $from)), $from);
        if ($existing) {
            $time === $before ? $existing->delete() : $existing->update(['start_time' => $time, 'created_by' => $actorId]);

            return;
        }
        if ($time !== static::at($userId, $from)) {
            static::create(['user_id' => $userId, 'start_time' => $time, 'effective_from' => $from, 'created_by' => $actorId]);
        }
    }
}
