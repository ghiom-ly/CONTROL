<?php

namespace App\Models;

use App\Enums\ConfirmationResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConfirmationOrder extends Model
{
    protected $fillable = ['daily_report_id', 'store_id', 'user_id', 'code', 'result', 'cancel_reason'];

    protected $casts = ['result' => ConfirmationResult::class];

    public function report(): BelongsTo
    {
        return $this->belongsTo(DailyReport::class, 'daily_report_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
