<?php

namespace App\Models;

use App\Enums\AlertLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Alert extends Model
{
    protected $fillable = [
        'rule_key', 'level', 'title', 'details', 'subject_type', 'subject_id', 'subject_label', 'fingerprint',
        'status', 'first_seen_at', 'last_seen_at', 'closed_at', 'closed_by', 'close_reason',
    ];

    protected $casts = [
        'level' => AlertLevel::class,
        'first_seen_at' => 'datetime', 'last_seen_at' => 'datetime', 'closed_at' => 'datetime',
    ];

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /** رابط الكيان المرتبط */
    public function subjectUrl(): ?string
    {
        return match ($this->subject_type) {
            'store' => route('stores.show', $this->subject_id),
            'issue' => route('issues.show', $this->subject_id),
            'report' => route('reports.show', $this->subject_id),
            'code' => route('shipments.show', $this->subject_label),
            'user' => auth()->user()?->hasPermission('users.view') ? route('users.show', $this->subject_id) : route('attendance.index'),
            default => null,
        };
    }
}
