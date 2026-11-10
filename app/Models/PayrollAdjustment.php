<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** إضافة أو خصم يدوي على راتب موظف في شهر — يبقى عند إعادة الحساب */
class PayrollAdjustment extends Model
{
    public const KINDS = ['addition' => 'إضافة', 'deduction' => 'خصم'];

    protected $fillable = ['payroll_month_id', 'user_id', 'kind', 'label', 'amount', 'created_by'];

    protected $casts = ['amount' => 'decimal:3'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
