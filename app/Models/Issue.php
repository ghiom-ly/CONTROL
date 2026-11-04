<?php

namespace App\Models;

use App\Enums\IssuePriority;
use App\Enums\IssueStatus;
use App\Enums\IssueType;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Issue extends Model
{
    use Auditable, SoftDeletes;

    protected $fillable = [
        'issue_number', 'source_key', 'store_id', 'shipment_id', 'daily_report_id', 'reported_by', 'reported_at', 'type',
        'title', 'description', 'priority', 'assigned_to', 'status', 'action_taken', 'due_at',
        'resolved_at', 'closed_at',
    ];

    protected $casts = [
        'type' => IssueType::class,
        'priority' => IssuePriority::class,
        'status' => IssueStatus::class,
        'reported_at' => 'datetime',
        'due_at' => 'datetime',
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // رقم مؤقت فريد حتى يُعرف المعرّف ثم يُستبدل بالرقم النهائي
        static::creating(function (self $model) {
            $model->issue_number ??= 'TMP-'.\Illuminate\Support\Str::random(20);
        });
        static::created(function (Issue $issue) {
            if (str_starts_with((string) $issue->issue_number, 'TMP-')) {
                $issue->forceFill(['issue_number' => sprintf('ISS-%s-%06d', now()->format('Y'), $issue->id)])->saveQuietly();
            }
        });
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function updates(): HasMany
    {
        return $this->hasMany(IssueUpdate::class)->latest();
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
