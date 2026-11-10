<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * طلب كرت تعبئة. رقم الكرت مشفّر في قاعدة البيانات ولا يُسجَّل في سجل العمليات
 * (لا نستخدم Auditable هنا؛ التسجيل يدوي بلا الرقم).
 */
class TopupRequest extends Model
{
    public const OPERATORS = ['libyana' => 'ليبيانا', 'madar' => 'المدار'];

    public const AMOUNTS = [3, 5];

    public const MAX_PENDING = 3;

    public const STATUSES = [
        'pending' => 'بانتظار الموافقة', 'issued' => 'تم إدراج الكرت', 'used' => 'تمت التعبئة',
        'faulty' => 'الكرت لا يعمل', 'rejected' => 'مرفوض', 'cancelled' => 'ملغى',
    ];

    public const STATUS_COLORS = [
        'pending' => 'yellow', 'issued' => 'blue', 'used' => 'green', 'faulty' => 'red', 'rejected' => 'red', 'cancelled' => 'gray',
    ];

    /** الحالات التي صُرف فيها كرت فعلاً ⇒ تدخل في التكاليف */
    public const COSTED = ['issued', 'used', 'faulty'];

    protected $fillable = ['user_id', 'operator', 'amount', 'note', 'status', 'card_number', 'card_hash',
        'decided_by', 'decided_at', 'decision_note', 'used_at', 'employee_note'];

    protected $hidden = ['card_number', 'card_hash'];

    protected $casts = ['amount' => 'integer', 'card_number' => 'encrypted', 'decided_at' => 'datetime', 'used_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by')->withTrashed();
    }

    public function summary(): string
    {
        return 'كرت '.self::OPERATORS[$this->operator].' فئة '.$this->amount.' د.ل';
    }

    /** أرقام فقط (يقبل الأرقام العربية والمسافات والشرطات) */
    public static function normalizeCard(?string $raw): string
    {
        return preg_replace('/[\s\-]+/u', '', (string) \App\Support\ArabicNormalizer::digits(trim((string) $raw)));
    }

    public static function hashCard(string $number): string
    {
        return hash_hmac('sha256', $number, (string) config('app.key'));
    }
}
