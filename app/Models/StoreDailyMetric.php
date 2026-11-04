<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreDailyMetric extends Model
{
    protected $fillable = [
        'store_id', 'metric_date', 'shipments_count', 'reports_count', 'followups_count',
        'delivered_after_followup', 'customer_no_answer', 'courier_no_answer', 'contact_attempts', 'issues_opened',
    ];

    protected $casts = ['metric_date' => 'date'];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
