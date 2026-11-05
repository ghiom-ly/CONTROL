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

    /** الأرشفة تحفظ تاريخ المتجر وتقاريره */
    public function archive(Store $store, User $actor): Store
    {
        return $this->update($store, ['status' => StoreStatus::Archived], $actor);
    }

    /** جداول تعني أن للمتجر تاريخاً لا يجوز فقدانه */
    private const HISTORY_TABLES = ['report_stores', 'shipments', 'shipment_followups', 'issues', 'contact_attempts', 'store_messages'];

    /**
     * حذف جماعي: المتجر بلا تاريخ يُحذف نهائياً، ومن له تقارير أو سجلات يُؤرشف بدل الحذف.
     *
     * @param  iterable<int>  $ids
     * @return array{deleted: int, archived: int}
     */
    public function bulkDelete(iterable $ids, User $actor): array
    {
        $deleted = $archived = 0;

        foreach (collect($ids)->unique()->chunk(500) as $chunk) {
            DB::transaction(function () use ($chunk, $actor, &$deleted, &$archived) {
                $withHistory = collect(self::HISTORY_TABLES)
                    ->flatMap(fn ($t) => DB::table($t)->whereIn('store_id', $chunk)->distinct()->pluck('store_id'))
                    ->unique()->flip();

                foreach (Store::withTrashed()->whereIn('id', $chunk)->get() as $store) {
                    if ($withHistory->has($store->id)) {
                        if ($store->status !== StoreStatus::Archived) {
                            $this->archive($store, $actor);
                        }
                        $archived++;
                    } else {
                        $store->forceDelete();
                        $deleted++;
                    }
                }
            });
        }

        return ['deleted' => $deleted, 'archived' => $archived];
    }
}
