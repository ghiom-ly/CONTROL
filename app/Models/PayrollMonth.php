<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollMonth extends Model
{
    protected $fillable = ['month', 'status', 'computed_at', 'computed_by', 'approved_at', 'approved_by'];

    protected $casts = ['month' => 'date', 'computed_at' => 'datetime', 'approved_at' => 'datetime'];

    public function items(): HasMany
    {
        return $this->hasMany(PayrollItem::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(PayrollAdjustment::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by')->withTrashed();
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }
}
