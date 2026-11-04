<?php

namespace App\Services\Imports;

use App\Enums\StoreStatus;
use App\Models\Store;
use App\Models\StoreAlias;
use App\Models\User;
use App\Support\ArabicNormalizer;

/**
 * مطابقة اسم متجر كما ورد في ملف/نص مع متاجر النظام: الاسم المطبَّع ثم الأسماء البديلة.
 */
class StoreMatcher
{
    /** @var array<string, ?int> */
    private array $cache = [];

    public function match(string $name): ?int
    {
        $key = ArabicNormalizer::normalize($name);
        if ($key === '') {
            return null;
        }

        return $this->cache[$key] ??= $this->lookup($key);
    }

    /** مثل match لكن يشمل المتاجر المؤرشفة (لاستيراد قائمة المتاجر: لا نُنشئ نسخة من مؤرشف) */
    public function matchAny(string $name): ?int
    {
        $key = ArabicNormalizer::normalize($name);
        if ($key === '') {
            return null;
        }

        return Store::withTrashed()->where('normalized_name', $key)->value('id')
            ?? StoreAlias::where('normalized_alias', $key)->value('store_id');
    }

    public function remember(string $name, int $storeId, User $actor): void
    {
        $key = ArabicNormalizer::normalize($name);
        $store = Store::find($storeId);
        if ($key === '' || ! $store || $store->normalized_name === $key) {
            return;
        }

        StoreAlias::updateOrCreate(['normalized_alias' => $key], [
            'store_id' => $storeId, 'alias' => mb_substr(trim($name), 0, 200), 'created_by' => $actor->id,
        ]);
        $this->cache[$key] = $storeId;
    }

    private function lookup(string $key): ?int
    {
        $active = fn ($q) => $q->where('status', '!=', StoreStatus::Archived)->whereNull('deleted_at');

        return Store::where('normalized_name', $key)->where($active)->value('id')
            ?? StoreAlias::where('normalized_alias', $key)->whereHas('store', $active)->value('store_id');
    }
}
