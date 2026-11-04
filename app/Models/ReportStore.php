<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReportStore extends Model
{
    protected $fillable = ['daily_report_id', 'store_id', 'shipments_count', 'contact_attempts', 'contact_times', 'notes'];

    protected $casts = ['contact_times' => 'array'];

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
