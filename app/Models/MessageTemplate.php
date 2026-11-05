<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** قالب رسالة للمتاجر — لا يُرسل إلا بعد اعتماد المدير العام */
class MessageTemplate extends Model
{
    use Auditable, SoftDeletes;

    public const TYPES = [
        'open_codes' => 'أكواد لم يرد عليها الزبون',
        'issue' => 'مشكلة مسجّلة على المتجر',
        'performance' => 'ملخص أداء المتجر',
        'general' => 'رسالة عامة',
    ];

    public const STATUSES = [
        'draft' => ['مسودة', 'gray'], 'pending' => ['بانتظار الاعتماد', 'yellow'], 'approved' => ['معتمد', 'green'],
        'rejected' => ['مُرجَع', 'red'], 'disabled' => ['موقوف', 'gray'],
    ];

    /** أنواع مسموحة في الإرسال الجماعي (المشكلة تحتاج اختيار مشكلة بعينها) */
    public const BULK_TYPES = ['general', 'performance', 'open_codes'];

    protected $fillable = ['name', 'type', 'body', 'status', 'version', 'review_note', 'created_by', 'updated_by', 'approved_by', 'approved_at'];

    protected $casts = ['approved_at' => 'datetime', 'version' => 'integer'];

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
