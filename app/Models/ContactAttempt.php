<?php

namespace App\Models;

use App\Enums\ContactParty;
use App\Enums\ContactResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactAttempt extends Model
{
    protected $fillable = [
        'party_type', 'store_id', 'shipment_id', 'shipment_code', 'daily_report_id', 'user_id',
        'contacted_at', 'attempt_number', 'result', 'notes',
    ];

    protected $casts = [
        'party_type' => ContactParty::class,
        'result' => ContactResult::class,
        'contacted_at' => 'datetime',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(DailyReport::class, 'daily_report_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
