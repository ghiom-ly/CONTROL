<?php

namespace App\Services\Reports;

use App\Models\Shipment;
use App\Models\ShipmentFollowup;

/**
 * يحدّث القيم الملخّصة للشحنة من آخر متابعة رسمية.
 * حالة المعالجة تُغيَّر فقط إذا كانت آخر متابعة من التقرير الجاري اعتماده،
 * حتى لا يُلغي إعادة اعتماد تقرير قديم تعليمَ المسؤول «تم الحل».
 */
class ShipmentSummaryRefresher
{
    public function refresh(Shipment $shipment, ?int $approvingReportId = null): void
    {
        $official = ShipmentFollowup::official()->where('shipment_id', $shipment->id);
        // المتابعات التاريخية (تقارير قديمة) تُعدّ ولا تحدد «آخر حالة» أبداً
        $last = (clone $official)->where('is_historical', false)->orderByDesc('followup_at')->orderByDesc('id')->first();

        $data = [
            'followups_count' => (clone $official)->count(),
            'last_result' => $last?->result,
            'customer_status' => $last?->customer_status,
            'courier_status' => $last?->courier_status,
            'last_followup_at' => $last?->followup_at,
            'last_followup_user_id' => $last?->user_id,
        ];

        $processing = $last?->result->impliedStatuses()['processing'];
        if ($processing && $last->daily_report_id === $approvingReportId) {
            $data['processing_status'] = $processing;
        }

        $shipment->fill($data)->save();
    }
}
