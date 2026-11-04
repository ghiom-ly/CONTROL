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
        'report_type', 'work_date', 'uploaded_by', 'uploaded_at', 'status', 'total_rows', 'accepted_rows',
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
