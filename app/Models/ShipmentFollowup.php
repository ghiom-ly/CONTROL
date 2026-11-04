<?php

namespace App\Models;

use App\Enums\CourierStatus;
use App\Enums\CustomerStatus;
use App\Enums\FollowupResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShipmentFollowup extends Model
{
    protected $fillable = [
        'shipment_id', 'daily_report_id', 'report_store_id', 'import_row_id', 'user_id', 'followup_at',
        'result', 'customer_status', 'courier_status', 'is_official', 'notes',
    ];

    protected $casts = [
        'followup_at' => 'datetime',
        'result' => FollowupResult::class,
        'customer_status' => CustomerStatus::class,
        'courier_status' => CourierStatus::class,
        'is_official' => 'boolean',
    ];

    public function scopeOfficial(Builder $q): Builder
    {
        return $q->where('is_official', true);
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(DailyReport::class, 'daily_report_id');
    }

    public function reportStore(): BelongsTo
    {
        return $this->belongsTo(ReportStore::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
