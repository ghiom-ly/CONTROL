<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** إجازة موظف يسجّلها المسؤول — أيامها لا تُحسب غياباً */
class Leave extends Model
{
    use Auditable;

    public const TYPES = ['leave' => 'إجازة', 'sick' => 'مرضية', 'mission' => 'مهمة عمل', 'other' => 'أخرى'];

    protected $fillable = ['user_id', 'from_date', 'to_date', 'type', 'notes', 'created_by'];

    protected $casts = ['from_date' => 'date', 'to_date' => 'date'];

    public function covers(CarbonInterface $day): bool
    {
        $d = $day->toDateString();

        return $this->from_date->toDateString() <= $d && $d <= $this->to_date->toDateString();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
