<?php

namespace App\Models;

use App\Enums\ImportStatus;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ImportBatch extends Model
{
    use Auditable, SoftDeletes;

    protected $fillable = [
        'batch_number', 'original_name', 'disk', 'stored_path', 'file_hash', 'file_size', 'file_type',
        'report_type', 'daily_report_id', 'work_date', 'uploaded_by', 'uploaded_at', 'status', 'total_rows', 'accepted_rows',
        'rejected_rows', 'errors', 'column_mapping', 'meta', 'approved_by', 'approved_at',
        'processing_started_at', 'processing_finished_at',
    ];

    protected $hidden = ['stored_path', 'disk'];

    protected $casts = [
        'status' => ImportStatus::class,
        'work_date' => 'date',
        'uploaded_at' => 'datetime',
        'approved_at' => 'datetime',
        'processing_started_at' => 'datetime',
        'processing_finished_at' => 'datetime',
        'errors' => 'array',
        'column_mapping' => 'array',
        'meta' => 'array',
    ];

    public const TYPES = [
        'shift_start' => 'ملف بداية الشفت',
        'shift_end' => 'ملف نهاية الشفت',
        'legacy_whatsapp' => 'تقرير قديم (واتساب)',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $b) {
            $b->batch_number ??= 'TMP-'.\Illuminate\Support\Str::random(20);
            $b->uploaded_at ??= now();
        });
        static::created(function (self $b) {
            if (str_starts_with((string) $b->batch_number, 'TMP-')) {
                $b->forceFill(['batch_number' => sprintf('IMP-%s-%06d', now()->format('Y'), $b->id)])->saveQuietly();
            }
        });
    }

    /** التقرير الذي طُبّق عليه الملف (أو أُنشئ منه) */
    public function report(): BelongsTo
    {
        return $this->belongsTo(DailyReport::class, 'daily_report_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(DailyReport::class);
    }
}
