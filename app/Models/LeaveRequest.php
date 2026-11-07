<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** طلب إجازة أو عذر يقدّمه الموظف — القبول يُنشئ إجازة أو استثناء حضور */
class LeaveRequest extends Model
{
    use Auditable;

    public const KINDS = ['leave' => 'إجازة', 'absence_excuse' => 'عذر غياب', 'late_excuse' => 'عذر تأخير'];

    public const STATUSES = ['pending' => 'قيد المراجعة', 'approved' => 'مقبول', 'rejected' => 'مرفوض', 'cancelled' => 'ملغى'];

    public const STATUS_COLORS = ['pending' => 'yellow', 'approved' => 'green', 'rejected' => 'red', 'cancelled' => 'gray'];

    public const DISK = 'local';

    protected $fillable = ['user_id', 'kind', 'leave_type', 'from_date', 'to_date', 'reason', 'attachment_path', 'attachment_name',
        'status', 'decided_by', 'decided_at', 'decision_note', 'leave_id', 'override_id'];

    protected $casts = ['from_date' => 'date', 'to_date' => 'date', 'decided_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by')->withTrashed();
    }

    /** وصف قصير: «إجازة مرضية 2026-11-08 → 2026-11-09» */
    public function summary(): string
    {
        $label = self::KINDS[$this->kind].($this->kind === 'leave' && $this->leave_type && $this->leave_type !== 'leave' ? ' ('.Leave::TYPES[$this->leave_type].')' : '');
        $dates = $this->from_date->toDateString().($this->to_date->ne($this->from_date) ? ' → '.$this->to_date->toDateString() : '');

        return $label.' '.$dates;
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
