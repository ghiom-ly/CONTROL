<?php

namespace App\Alerts\Rules;

use App\Alerts\AlertRule;
use App\Alerts\Finding;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** متجر عليه ≥ count مشكلات خلال days يوماً */
class RepeatedStoreIssue implements AlertRule
{
    public function key(): string
    {
        return 'repeated_store_issue';
    }

    public function evaluate(array $params, CarbonImmutable $now): array
    {
        $count = (int) ($params['count'] ?? 3);
        $rows = DB::table('issues')->whereNull('deleted_at')->whereNotNull('store_id')
            ->where('reported_at', '>=', $now->subDays((int) ($params['days'] ?? 30)))
            ->selectRaw('store_id, COUNT(*) n')->groupBy('store_id')->having('n', '>=', $count)->get();
        $stores = Store::withTrashed()->whereIn('id', $rows->pluck('store_id'))->pluck('name', 'id');

        return $rows->filter(fn ($r) => isset($stores[$r->store_id]))->map(fn ($r) => new Finding(
            "store:{$r->store_id}:issues", 'تكرار المشكلات على '.$stores[$r->store_id].' ('.$r->n.')',
            null, 'store', (int) $r->store_id, $stores[$r->store_id],
        ))->values()->all();
    }
}
