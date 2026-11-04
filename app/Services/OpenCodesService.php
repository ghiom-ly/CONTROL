<?php

namespace App\Services;

use App\Enums\FollowupResult;
use App\Enums\ProcessingStatus;
use App\Models\Shipment;
use App\Models\User;
use App\Queries\OpenCodesQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class OpenCodesService
{
    public const TABS = [
        'customer' => FollowupResult::CustomerNoAnswer,
        'courier' => FollowupResult::CourierNoAnswer,
    ];

    public function query(string $tab, array $filters, User $viewer): Builder
    {
        return OpenCodesQuery::base()
            ->where('last_result', self::TABS[$tab])
            ->when(! $viewer->hasPermission('reports.view_all'), fn ($q) => $q->where('last_followup_user_id', $viewer->id))
            ->when($filters['store_id'] ?? null, fn ($q, $id) => $q->where('store_id', $id))
            ->when($filters['user_id'] ?? null, fn ($q, $id) => $q->where('last_followup_user_id', $id))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->whereDate('last_followup_at', '>=', $d))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->whereDate('last_followup_at', '<=', $d));
    }

    /** forwarded = تم التحويل للمتجر (قيد المعالجة)، resolved = تم الحل */
    public function mark(array $shipmentIds, string $action, ?string $note, User $actor): int
    {
        $status = $action === 'resolved' ? ProcessingStatus::Resolved : ProcessingStatus::InProgress;
        $label = $action === 'resolved' ? 'تم الحل' : 'تم التحويل للمتجر';

        return DB::transaction(function () use ($shipmentIds, $status, $label, $note, $actor) {
            $shipments = Shipment::whereIn('id', $shipmentIds)->lockForUpdate()->get();
            foreach ($shipments as $s) {
                $line = now()->format('Y/m/d H:i').' — '.$actor->name.': '.$label.($note ? ' — '.$note : '');
                $s->fill([
                    'processing_status' => $status,
                    'notes' => trim(($s->notes ? $s->notes."\n" : '').$line),
                ])->save();
            }

            return $shipments->count();
        });
    }
}
