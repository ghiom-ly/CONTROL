<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReportStore extends Model
{
    use HasFactory;

    protected $fillable = [
        'daily_report_id', 'store_id', 'shipments_count', 'contact_attempts', 'contact_times', 'notes', 'is_outside_assignment',
        'shipments_count_source', 'file_orders_count', 'file_delivered_count', 'end_file_orders_count', 'end_file_delivered_count',
    ];

    protected $casts = [
        'contact_times' => 'array', 'is_outside_assignment' => 'boolean', 'shipments_count' => 'integer',
        'file_orders_count' => 'integer', 'file_delivered_count' => 'integer',
        'end_file_orders_count' => 'integer', 'end_file_delivered_count' => 'integer',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(DailyReport::class, 'daily_report_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function followups(): HasMany
    {
        return $this->hasMany(ShipmentFollowup::class);
    }
}
