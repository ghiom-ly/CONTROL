<?php

namespace App\Services\Reports;

use App\Enums\ReportStatus;
use App\Models\DailyReport;
use App\Models\Shipment;
use Illuminate\Support\Collection;

/**
 * الكود ينتمي لمتجر واحد. المصدر الرسمي هو جدول shipments (يُبنى عند الاعتماد فقط).
 */
class ShipmentCodeGuard
{
    /** معرّف المتجر الرسمي للكود أو null إن لم يُعتمد بعد */
    public function officialOwner(string $code): ?int
    {
        return Shipment::where('code', $code)->value('store_id');
    }

    /** @return array<string,int> code => store_id للأكواد المعتمدة */
    public function officialOwners(array $codes): array
    {
        return $codes === [] ? [] : Shipment::whereIn('code', $codes)->pluck('store_id', 'code')->all();
    }

    /**
     * تقارير أخرى غير معتمدة سُجّل فيها الكود نفسه لمتجر مختلف.
     *
     * @return Collection<int, DailyReport>
     */
    public function pendingConflicts(string $code, int $storeId, int $exceptReportId): Collection
    {
        return DailyReport::query()
            ->whereKeyNot($exceptReportId)
            ->where('status', '!=', ReportStatus::Approved)
            ->whereHas('followups', fn ($q) => $q->where('shipment_code', $code)->where('store_id', '!=', $storeId))
            ->get(['id', 'report_number', 'user_id']);
    }
}
