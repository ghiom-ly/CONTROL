<?php

namespace App\Models\Concerns;

use App\Services\AuditLogger;

/**
 * يسجّل تلقائياً عمليات الإنشاء والتعديل والحذف في audit_logs.
 * الحقول الحساسة (كلمة المرور، remember_token) لا تُسجَّل أبداً.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn ($model) => AuditLogger::model('created', $model, [], $model->auditableAttributes($model->getAttributes())));

        static::updated(function ($model) {
            $changes = $model->auditableAttributes($model->getChanges());
            unset($changes['updated_at']);
            if ($changes === []) {
                return;
            }
            $old = array_intersect_key($model->auditableAttributes($model->getOriginal()), $changes);
            AuditLogger::model('updated', $model, $old, $changes);
        });

        static::deleted(fn ($model) => AuditLogger::model('deleted', $model, $model->auditableAttributes($model->getOriginal()), []));

        if (method_exists(static::class, 'restored')) {
            static::restored(fn ($model) => AuditLogger::model('restored', $model, [], []));
        }
    }

    protected function auditableAttributes(array $attributes): array
    {
        $hidden = array_merge(['password', 'remember_token'], $this->auditExclude ?? []);

        return array_diff_key($attributes, array_flip($hidden));
    }
}
