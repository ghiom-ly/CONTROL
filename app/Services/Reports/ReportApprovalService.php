<?php

namespace App\Services\Reports;

use App\Enums\IssueStatus;
use App\Enums\ProcessingStatus;
use App\Enums\ReportStatus;
use App\Models\DailyReport;
use App\Models\Issue;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\User;
use App\Notifications\ReportReviewedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * الاعتماد يجعل التقرير رسمياً. العملية كلها قابلة لإعادة التشغيل (إعادة الاعتماد بعد تعديل إداري آمنة).
 */
class ReportApprovalService
{
    public function __construct(
        private readonly ShipmentSummaryRefresher $summaries,
        private readonly StoreMetricsBuilder $metrics,
    ) {}

    /**
     * الشحنات ومفاتيح المقاييس التي يمسّها التقرير بحالته الحالية (تُؤخذ قبل أي تعديل).
     *
     * @return array{shipment_ids: list<int>, metric_keys: list<array{0:int,1:string}>}
     */
    public function affectedKeys(DailyReport $report): array
    {
        $date = $report->work_date->toDateString();
        $storeIds = $report->stores()->pluck('store_id')->merge($report->followups()->pluck('store_id'))->unique();

        return [
            'shipment_ids' => $report->followups()->whereNotNull('shipment_id')->pluck('shipment_id')
                ->merge(Shipment::where('first_report_id', $report->id)->pluck('id'))->unique()->values()->all(),
            'metric_keys' => $storeIds->map(fn ($id) => [(int) $id, $date])->values()->all(),
        ];
    }

    /**
     * @param  list<ReportStatus>  $allowed  الحالات المسموح اعتمادها (تُفحص بعد القفل)
     * @return list<string> تحذيرات (تعارضات التقارير القديمة)
     */
    public function approve(DailyReport $report, User $actor, ?array $previous = null, array $allowed = [ReportStatus::PendingReview]): array
    {
        return DB::transaction(function () use ($report, $actor, $previous, $allowed) {
            $warnings = [];
            $report = DailyReport::whereKey($report->id)->lockForUpdate()->firstOrFail();
            if (! in_array($report->status, $allowed, true)) {
                throw ValidationException::withMessages(['status' => 'تغيّرت حالة التقرير — لم يعد قيد المراجعة.']);
            }
            $previous ??= $this->affectedKeys($report);
            $followups = $report->followups()->get();

            $historical = $report->is_legacy;
            if ($historical) {
                $warnings = $this->legacyConflicts($followups);
            } else {
                $this->guardOwnership($report, $followups);
            }

            // 3) ربط/إنشاء الشحنات وجعل المتابعات رسمية
            $shipmentIds = [];
            foreach ($followups as $f) {
                $shipment = Shipment::withTrashed()->where('code', $f->shipment_code)->lockForUpdate()->first();
                if (! $shipment) {
                    $shipment = Shipment::create([
                        'code' => $f->shipment_code, 'store_id' => $f->store_id, 'registered_at' => $report->work_date,
                        'first_report_id' => $report->id, 'created_by' => $actor->id,
                        // كود ظهر في تقرير قديم فقط: سجل تاريخي لا يظهر كمفتوح
                        'processing_status' => $historical ? ProcessingStatus::Closed : ProcessingStatus::Open,
                    ]);
                } elseif ($shipment->trashed()) {
                    // شحنة محذوفة تُستعاد كأنها جديدة من هذا التقرير
                    $shipment->restore();
                    $shipment->fill(['store_id' => $f->store_id, 'first_report_id' => $report->id, 'registered_at' => $report->work_date])->save();
                }

                $implied = $f->result->impliedStatuses();
                $f->fill([
                    'shipment_id' => $shipment->id,
                    'courier_status' => $implied['courier'],
                    'customer_status' => $implied['customer'],
                    'is_official' => true,
                    'is_historical' => $historical,
                ])->save();
                $shipmentIds[] = $shipment->id;
            }

            // 4) شحنات أنشأها هذا التقرير ولم يعد لها أي متابعة
            $orphans = Shipment::whereIn('id', array_diff($previous['shipment_ids'], $shipmentIds))
                ->where('first_report_id', $report->id)->whereDoesntHave('followups')->get();
            $orphans->each->delete();

            // 5) ملخصات الشحنات
            Shipment::whereIn('id', array_unique(array_merge($previous['shipment_ids'], $shipmentIds)))->get()
                ->each(fn (Shipment $s) => $this->summaries->refresh($s, $report->id));

            // 6) المشكلات المطلوبة من القسم ④ (لا تتكرر ولا تُحذف)
            foreach ($report->issue_requests ?? [] as $req) {
                Issue::firstOrCreate(
                    ['daily_report_id' => $report->id, 'source_key' => $req['key']],
                    [
                        'store_id' => $req['store_id'], 'reported_by' => $report->user_id, 'reported_at' => now(),
                        'type' => $req['type'], 'title' => $req['title'], 'description' => $req['description'],
                        'status' => IssueStatus::New,
                    ],
                );
            }

            // 8) حالة التقرير (قبل بناء المقاييس لأنها تُبنى من المعتمد فقط)
            $report->fill([
                'status' => ReportStatus::Approved,
                'approved_by' => $actor->id, 'approved_at' => now(),
                'reviewed_by' => $actor->id, 'reviewed_at' => now(),
                'rejection_reason' => null,
            ])->save();

            // 7) المقاييس للمفاتيح القديمة والجديدة
            $keys = collect($previous['metric_keys'])->merge($this->affectedKeys($report)['metric_keys'])
                ->unique(fn ($k) => $k[0].'|'.$k[1]);
            foreach ($keys as [$storeId, $date]) {
                $this->metrics->rebuild($storeId, $date);
            }

            $report->loadMissing('user')->user->notify(new ReportReviewedNotification($report));

            return $warnings;
        });
    }

    public function reject(DailyReport $report, string $reason, User $actor): void
    {
        DB::transaction(function () use ($report, $reason, $actor) {
            $report = DailyReport::whereKey($report->id)->lockForUpdate()->firstOrFail();
            $this->ensurePending($report);
            $report->fill([
                'status' => ReportStatus::Rejected, 'rejection_reason' => $reason,
                'reviewed_by' => $actor->id, 'reviewed_at' => now(),
            ])->save();
            $report->loadMissing('user')->user->notify(new ReportReviewedNotification($report));
        });
    }

    public function ensurePending(DailyReport $report): void
    {
        if ($report->status !== ReportStatus::PendingReview) {
            throw ValidationException::withMessages(['status' => 'التقرير ليس قيد المراجعة.']);
        }
    }

    /** للتقارير القديمة: التعارض لا يمنع، يُذكر فقط */
    private function legacyConflicts($followups): array
    {
        $existing = Shipment::whereIn('code', $followups->pluck('shipment_code'))->get()->keyBy('code');
        $out = [];
        foreach ($followups as $f) {
            $s = $existing[$f->shipment_code] ?? null;
            if ($s && $s->store_id !== $f->store_id) {
                $out[] = "الكود {$f->shipment_code} مسجّل لمتجر ".(Store::find($s->store_id)?->name ?? $s->store_id).' — حُفظ كسجل تاريخي دون تغيير حالته.';
            }
        }

        return $out;
    }

    /** الكود المعتمد لمتجر آخر يمنع الاعتماد (إلا إن كان هذا التقرير وحده من أنشأه) */
    private function guardOwnership(DailyReport $report, $followups): void
    {
        $existing = Shipment::whereIn('code', $followups->pluck('shipment_code'))->get()->keyBy('code');
        $conflicts = [];
        foreach ($followups as $f) {
            $s = $existing[$f->shipment_code] ?? null;
            if (! $s || $s->store_id === $f->store_id) {
                continue;
            }
            $onlyThisReport = $s->first_report_id === $report->id
                && ! $s->followups()->where('daily_report_id', '!=', $report->id)->exists();
            if (! $onlyThisReport) {
                $conflicts[] = $f->shipment_code.' (مسجّل لمتجر '.(Store::find($s->store_id)?->name ?? $s->store_id).')';
            } else {
                $s->fill(['store_id' => $f->store_id])->save();
            }
        }
        if ($conflicts) {
            throw ValidationException::withMessages(['codes' => 'لا يمكن الاعتماد — أكواد مسجّلة لمتاجر أخرى: '.implode('، ', $conflicts)]);
        }
    }
}
