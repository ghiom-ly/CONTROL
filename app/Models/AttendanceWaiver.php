<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** إلغاء جماعي للتأخير و/أو الغياب لكل الموظفين خلال فترة (مثلاً عطل في المنظومة) */
class AttendanceWaiver extends Model
{
    use Auditable;

    public const MAX_DAYS = 62;

    protected $fillable = ['from_date', 'to_date', 'cancel_late', 'cancel_absence', 'reason', 'created_by'];

    protected $casts = ['from_date' => 'date', 'to_date' => 'date', 'cancel_late' => 'boolean', 'cancel_absence' => 'boolean'];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    /**
     * ما يُلغى في كل يوم من الفترة — يدمج المفتاحين العامّين في الإعدادات مع الإلغاءات الجماعية.
     *
     * @return array{late_off: bool, absence_off: bool, days: array<string, array{late: bool, absence: bool, reason: ?string}>}
     */
    public static function forRange(string $from, string $to): array
    {
        $days = [];
        foreach (static::where('from_date', '<=', $to)->where('to_date', '>=', $from)->orderBy('id')->get() as $w) {
            $a = max($from, $w->from_date->toDateString());
            $b = min($to, $w->to_date->toDateString());
            for ($d = \Carbon\CarbonImmutable::parse($a); $d->toDateString() <= $b; $d = $d->addDay()) {
                $k = $d->toDateString();
                $cur = $days[$k] ?? ['late' => false, 'absence' => false, 'reason' => null];
                $days[$k] = [
                    'late' => $cur['late'] || $w->cancel_late,
                    'absence' => $cur['absence'] || $w->cancel_absence,
                    'reason' => $cur['reason'] ?? ($w->reason ?: null),
                ];
            }
        }

        return [
            'late_off' => ! SystemSetting::get('attendance_track_late', true),
            'absence_off' => ! SystemSetting::get('attendance_track_absence', true),
            'days' => $days,
        ];
    }
}
