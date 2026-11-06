<?php

namespace App\Services\Reports;

use App\Models\DailyReport;
use App\Models\ReportRevision;
use App\Models\User;

class ReportRevisionService
{
    /** نسخة واحدة فقط لكل رقم نسخة — تحفظ التقرير كما كان قبل أول تعديل عليه */
    public function snapshotOnce(DailyReport $report, string $reason, User $actor): ReportRevision
    {
        return ReportRevision::where('daily_report_id', $report->id)->where('version', $report->version)->first()
            ?? $this->snapshot($report, $reason, $actor);
    }

    /** نسخة كاملة من التقرير بحالته الحالية قبل أي تعديل جوهري */
    public function snapshot(DailyReport $report, string $reason, User $actor): ReportRevision
    {
        $report->loadMissing(['stores', 'followups', 'attachments']);

        return ReportRevision::create([
            'daily_report_id' => $report->id,
            'version' => $report->version,
            'reason' => $reason,
            'changed_by' => $actor->id,
            'snapshot' => [
                'report' => collect($report->getAttributes())->except(['id'])->all() + ['status' => $report->status->value],
                'stores' => $report->stores->map->only(['store_id', 'shipments_count', 'notes', 'is_outside_assignment'])->values()->all(),
                'followups' => $report->followups->map(fn ($f) => [
                    'code' => $f->shipment_code, 'store_id' => $f->store_id, 'result' => $f->result->value,
                ])->values()->all(),
                'attachment_ids' => $report->attachments->pluck('id')->all(),
            ] + ($report->isConfirmation() ? [
                'confirmation_orders' => $report->confirmationOrders()->get()
                    ->map(fn ($o) => ['store_id' => $o->store_id, 'code' => $o->code, 'result' => $o->result->value, 'cancel_reason' => $o->cancel_reason])
                    ->values()->all(),
            ] : []),
        ]);
    }
}
