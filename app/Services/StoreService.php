<?php

namespace App\Services;

use App\Enums\StoreStatus;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class StoreService
{
    public function create(array $data, User $actor): Store
    {
        return DB::transaction(fn () => Store::create($data + ['created_by' => $actor->id]));
    }

    public function update(Store $store, array $data, User $actor): Store
    {
        return DB::transaction(function () use ($store, $data, $actor) {
            $store->fill($data + ['updated_by' => $actor->id])->save();

            return $store;
        });
    }

    /** لا حذف للمتاجر — الأرشفة تحفظ تاريخها وتقاريرها */
    public function archive(Store $store, User $actor): Store
    {
        return $this->update($store, ['status' => StoreStatus::Archived], $actor);
    }
}
