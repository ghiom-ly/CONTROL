<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'event', 'auditable_type', 'auditable_id', 'description', 'old_values', 'new_values',
        'ip_address', 'user_agent', 'url',
    ];

    protected $casts = ['old_values' => 'array', 'new_values' => 'array', 'created_at' => 'datetime'];

    public const EVENT_LABELS = [
        'created' => 'إنشاء', 'updated' => 'تعديل', 'deleted' => 'حذف', 'restored' => 'استعادة',
        'login' => 'تسجيل دخول', 'logout' => 'تسجيل خروج', 'login_failed' => 'محاولة دخول فاشلة',
        'approved' => 'اعتماد', 'rejected' => 'رفض', 'submitted' => 'تقديم للمراجعة',
        'password_reset' => 'إعادة تعيين كلمة المرور', 'permissions_updated' => 'تعديل الصلاحيات',
        'settings_updated' => 'تعديل الإعدادات', 'exported' => 'تصدير', 'imported' => 'استيراد',
        'file_downloaded' => 'تنزيل ملف',
    ];

    public const MODEL_LABELS = [
        User::class => 'مستخدم', Store::class => 'متجر', DailyReport::class => 'تقرير يومي',
        Shipment::class => 'شحنة', Issue::class => 'مشكلة', ImportBatch::class => 'عملية استيراد',
        Role::class => 'دور', SystemSetting::class => 'إعداد',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function eventLabel(): string
    {
        return self::EVENT_LABELS[$this->event] ?? $this->event;
    }

    public function modelLabel(): ?string
    {
        return $this->auditable_type ? (self::MODEL_LABELS[$this->auditable_type] ?? class_basename($this->auditable_type)) : null;
    }
}
