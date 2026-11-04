<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportRevision extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['daily_report_id', 'version', 'snapshot', 'reason', 'changed_by'];

    protected $casts = ['snapshot' => 'array'];

    public function report(): BelongsTo
    {
        return $this->belongsTo(DailyReport::class, 'daily_report_id');
    }

    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
