<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** سطر موظف في كشف الشهر — لقطة محفوظة لا تتغير بعد الاعتماد */
class PayrollItem extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'base_salary' => 'decimal:3', 'day_value' => 'decimal:3', 'base_due' => 'decimal:3',
        'absence_deduction' => 'decimal:3', 'incomplete_deduction' => 'decimal:3',
        'additions' => 'decimal:3', 'deductions' => 'decimal:3', 'net' => 'decimal:3',
        'absent_dates' => 'array', 'incomplete_dates' => 'array',
        'paid_days' => 'integer', 'absent_days' => 'integer', 'incomplete_days' => 'integer', 'incomplete_percent' => 'integer',
        'late_count' => 'integer', 'late_minutes' => 'integer', 'reports_approved' => 'integer', 'reports_total' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function payrollMonth(): BelongsTo
    {
        return $this->belongsTo(PayrollMonth::class);
    }

    /** تنسيق مبلغ بالدينار: حتى 3 خانات بلا أصفار زائدة */
    public static function money(mixed $v): string
    {
        $s = number_format((float) $v, 3, '.', ',');

        return rtrim(rtrim($s, '0'), '.');
    }
}
