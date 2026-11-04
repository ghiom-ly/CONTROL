<?php

namespace App\Models;

use App\Enums\ReportStatus;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DailyReport extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $fillable = [
        'report_number', 'work_date', 'user_id', 'status', 'source', 'import_batch_id',
        'total_shipments', 'contact_attempts_count', 'notes', 'inquiries', 'version',
        'submitted_at', 'reviewed_by', 'reviewed_at', 'review_notes', 'approved_by',
        'approved_at', 'rejection_reason', 'created_by', 'updated_by',
        'shift_started_at', 'start_total_orders', 'start_delivery_rate', 'end_delivery_rate',
        'attempt_times', 'attempts_over_limit_reason', 'issue_requests',
        'is_legacy', 'file_values', 'value_sources',
    ];

    protected $casts = [
        'status' => ReportStatus::class,
        'work_date' => 'date',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'approved_at' => 'datetime',
        'shift_started_at' => 'datetime',
        'start_total_orders' => 'integer',
        'start_delivery_rate' => 'decimal:2',
        'end_delivery_rate' => 'decimal:2',
        'attempt_times' => 'array',
        'issue_requests' => 'array',
        'is_legacy' => 'boolean',
        'file_values' => 'array',
        'value_sources' => 'array',
    ];

    protected static function booted(): void
    {
        // رقم مؤقت فريد حتى يُعرف المعرّف ثم يُستبدل بالرقم النهائي
        static::creating(function (self $model) {
            $model->report_number ??= 'TMP-'.\Illuminate\Support\Str::random(20);
        });
        static::created(function (DailyReport $report) {
            if (str_starts_with((string) $report->report_number, 'TMP-')) {
                $report->forceFill([
                    'report_number' => sprintf('RPT-%s-%06d', now()->format('Y'), $report->id),
                ])->saveQuietly();
            }
        });
    }

    /** التقارير المعتمدة فقط تدخل في الإحصائيات الرسمية */
    public function scopeOfficial(Builder $query): Builder
    {
        return $query->where('status', ReportStatus::Approved);
    }

    /** الشفت المفتوح (مسودة) */
    public function scopeOpenDraft(Builder $query): Builder
    {
        return $query->where('status', ReportStatus::Draft);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class);
    }

    public function stores(): HasMany
    {
        return $this->hasMany(ReportStore::class);
    }

    public function followups(): HasMany
    {
        return $this->hasMany(ShipmentFollowup::class);
    }

    public function contactAttempts(): HasMany
    {
        return $this->hasMany(ContactAttempt::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(ReportRevision::class)->orderByDesc('version');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /**
     * تنبيهات للمسؤول: over_attempts, outside_assignment, courier_without_screens
     *
     * @return list<string>
     */
    public function warningFlags(): array
    {
        $flags = [];
        if (count($this->attempt_times ?? []) > \App\Services\Reports\ReportDraftService::maxAttempts()) {
            $flags[] = 'over_attempts';
        }
        if ($this->stores()->where('is_outside_assignment', true)->exists()) {
            $flags[] = 'outside_assignment';
        }
        if ($this->followups()->where('result', \App\Enums\FollowupResult::CourierNoAnswer)->exists()
            && ! $this->screens('courier_screens')->exists()) {
            $flags[] = 'courier_without_screens';
        }

        return $flags;
    }

    public const WARNING_LABELS = [
        'over_attempts' => 'تجاوز حد المحاولات',
        'outside_assignment' => 'متاجر خارج الإسناد',
        'courier_without_screens' => 'قائمة المندوب بدون سكرينات',
    ];

    public function screens(string $category): MorphMany
    {
        return $this->attachments()->where('category', $category);
    }
}
