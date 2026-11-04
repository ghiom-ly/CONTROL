<?php

namespace App\Models;

use App\Enums\CourierStatus;
use App\Enums\CustomerStatus;
use App\Enums\FollowupResult;
use App\Enums\ProcessingStatus;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Shipment extends Model
{
    use Auditable, SoftDeletes;

    protected $fillable = [
        'code', 'store_id', 'registered_at', 'first_report_id', 'last_followup_user_id', 'last_result',
        'customer_status', 'courier_status', 'processing_status', 'followups_count', 'last_followup_at',
        'notes', 'created_by',
    ];

    protected $casts = [
        'registered_at' => 'date',
        'last_followup_at' => 'datetime',
        'last_result' => FollowupResult::class,
        'customer_status' => CustomerStatus::class,
        'courier_status' => CourierStatus::class,
        'processing_status' => ProcessingStatus::class,
    ];

    /** توحيد صيغة الكود: إزالة المسافات وتحويل الأرقام العربية */
    public static function normalizeCode(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }
        // علامات الاتجاه الخفية (RLM/LRM/BOM...) التي يضيفها واتساب عند النسخ
        $code = preg_replace('/\p{Cf}+/u', '', $code);
        $code = strtr(trim($code), [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
        $code = preg_replace('/\s+/u', '', $code);

        return $code === '' ? null : mb_strtoupper($code);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function firstReport(): BelongsTo
    {
        return $this->belongsTo(DailyReport::class, 'first_report_id');
    }

    public function lastFollowupUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_followup_user_id');
    }

    public function followups(): HasMany
    {
        return $this->hasMany(ShipmentFollowup::class)->orderByDesc('followup_at');
    }

    public function contactAttempts(): HasMany
    {
        return $this->hasMany(ContactAttempt::class);
    }
}
