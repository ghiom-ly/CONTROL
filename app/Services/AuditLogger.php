<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Throwable;

/**
 * نقطة مركزية لكتابة سجل التدقيق. لا يجب أن يُفشل تسجيلُ التدقيق العمليةَ الأصلية.
 */
class AuditLogger
{
    public static function model(string $event, Model $model, array $old = [], array $new = [], ?string $description = null): void
    {
        static::log($event, $description, $model, $old, $new);
    }

    public static function log(string $event, ?string $description = null, ?Model $model = null, array $old = [], array $new = [], ?int $userId = null): void
    {
        try {
            $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : Request::instance();
            AuditLog::create([
                'user_id' => $userId ?? Auth::id(),
                'event' => $event,
                'auditable_type' => $model ? $model::class : null,
                'auditable_id' => $model?->getKey(),
                'description' => $description,
                'old_values' => $old ? static::clean($old) : null,
                'new_values' => $new ? static::clean($new) : null,
                'ip_address' => $request?->ip(),
                'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 500) : null,
                'url' => $request ? mb_substr($request->fullUrl(), 0, 500) : null,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private static function clean(array $values): array
    {
        return array_map(function ($v) {
            if ($v instanceof \BackedEnum) {
                return $v->value;
            }
            if ($v instanceof \DateTimeInterface) {
                return $v->format('Y-m-d H:i:s');
            }

            return is_scalar($v) || $v === null ? $v : json_decode(json_encode($v), true);
        }, $values);
    }
}
