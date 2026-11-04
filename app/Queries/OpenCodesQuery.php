<?php

namespace App\Queries;

use App\Enums\FollowupResult;
use App\Enums\ProcessingStatus;
use App\Models\Shipment;
use Illuminate\Database\Eloquent\Builder;

/**
 * الأكواد المفتوحة غير المُجاب عليها: شحنات رسمية (لا تُنشأ إلا عند الاعتماد)
 * آخر نتيجة لها «الزبون لم يرد» أو «المندوب لم يرد» ولم تُعلَّم محلولة أو مغلقة.
 */
class OpenCodesQuery
{
    public static function base(): Builder
    {
        return Shipment::query()
            ->whereIn('last_result', [FollowupResult::CustomerNoAnswer, FollowupResult::CourierNoAnswer])
            ->whereNotIn('processing_status', [ProcessingStatus::Resolved, ProcessingStatus::Closed]);
    }

    public static function forStore(int $storeId): Builder
    {
        return static::base()->where('store_id', $storeId);
    }
}
