<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * الراتب الأساسي بتاريخ سريان (مثل وقت الشفت). amount = null يعني «بلا راتب».
 * لا يستخدم Auditable حتى لا تظهر المبالغ في سجل العمليات.
 */
class EmployeeSalary extends Model
{
    protected $fillable = ['user_id', 'amount', 'effective_from', 'created_by'];

    protected $casts = ['effective_from' => 'date', 'amount' => 'decimal:3'];

    public static function at(int $userId, string $date): ?float
    {
        $row = static::where('user_id', $userId)->whereDate('effective_from', '<=', $date)->orderByDesc('effective_from')->first();

        return $row?->amount === null ? null : (float) $row->amount;
    }

    public static function currentFor(int $userId): ?float
    {
        return static::at($userId, now()->toDateString());
    }

    /** يسجّل التغيير إن اختلف عن الساري في ذلك التاريخ؛ نفس التاريخ ⇒ يستبدل. يعيد true إن تغيّر شيء */
    public static function set(int $userId, ?float $amount, string $from, ?int $actorId): bool
    {
        $existing = static::where('user_id', $userId)->whereDate('effective_from', $from)->first();
        $before = static::where('user_id', $userId)->whereDate('effective_from', '<', $from)->orderByDesc('effective_from')->first();
        $beforeAmount = $before?->amount === null ? null : (float) $before->amount;
        if ($existing) {
            $current = $existing->amount === null ? null : (float) $existing->amount;
            if ($current === $amount) {
                return false;
            }
            $amount === $beforeAmount ? $existing->delete() : $existing->update(['amount' => $amount, 'created_by' => $actorId]);

            return true;
        }
        if ($amount === static::at($userId, $from)) {
            return false;
        }
        static::create(['user_id' => $userId, 'amount' => $amount, 'effective_from' => $from, 'created_by' => $actorId]);

        return true;
    }
}
