<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class SystemSetting extends Model
{
    protected $fillable = ['key', 'value', 'type', 'group', 'label', 'description', 'updated_by'];

    public const CACHE_KEY = 'system_settings.all';

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CACHE_KEY));
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = Cache::rememberForever(self::CACHE_KEY, fn () => static::query()->get(['key', 'value', 'type'])
            ->mapWithKeys(fn ($s) => [$s->key => $s->castValue()])->all());

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public function castValue(): mixed
    {
        if ($this->value === null) {
            return null;
        }

        return match ($this->type) {
            'integer' => (int) $this->value,
            'decimal' => (float) $this->value,
            'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode($this->value, true),
            default => $this->value,
        };
    }
}
