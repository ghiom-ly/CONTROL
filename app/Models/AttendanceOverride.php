<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** استثناء حضور يضعه المدير العام: غياب بعذر، حاضر، أو إلغاء تأخير */
class AttendanceOverride extends Model
{
    use Auditable;

    public const TYPES = ['excused' => 'غياب بعذر', 'present' => 'حاضر (بتعديل)', 'late_excused' => 'إلغاء التأخير'];

    protected $fillable = ['user_id', 'date', 'type', 'reason', 'created_by'];

    protected $casts = ['date' => 'date'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
