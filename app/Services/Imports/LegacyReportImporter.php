<?php

namespace App\Services\Imports;

use App\Enums\ImportStatus;
use App\Enums\ReportStatus;
use App\Models\DailyReport;
use App\Models\ImportBatch;
use App\Models\User;
use App\Services\Reports\ReportDraftService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * يحوّل تقريراً قديماً مقروءاً من نص واتساب إلى تقرير «قيد المراجعة» (is_legacy).
 * عند الاعتماد تصبح متابعاته سجلاً تاريخياً فقط (انظر ReportApprovalService).
 */
class LegacyReportImporter
{
    public function __construct(
        private readonly StoreMatcher $matcher,
        private readonly ReportDraftService $drafts,
    ) {}

    /** يضيف لكل عنصر مقروء: store_id المقترح لكل متجر وتحذيرات التكرار */
    public function annotate(array $item, ?int $userId): array
    {
        $item['stores'] = array_map(fn ($s) => $s + ['store_id' => $this->matcher->match($s['name'])], $item['stores']);
        $item['warnings'] = [];
        if ($userId && $item['date'] && DailyReport::where('user_id', $userId)->whereDate('work_date', $item['date'])->exists()) {
            $item['warnings'][] = 'يوجد تقرير لنفس الموظف في هذا التاريخ.';
        }
        if (ImportBatch::where('file_hash', $item['hash'])->where('status', '!=', ImportStatus::Cancelled)->exists()) {
            $item['warnings'][] = 'هذا النص استُورد سابقاً.';
        }

        return $item;
    }

    /**
     * @param  array<string, int|string>  $choices  اسم المتجر في النص => معرّف متجر أو 'ignore'
     * @return array{report: DailyReport, warnings: list<string>}
     */
    public function create(array $item, User $owner, User $actor, array $choices = [], array $listStores = []): array
    {
        if (empty($item['date'])) {
            throw ValidationException::withMessages(['date' => 'تاريخ التقرير غير موجود أو غير صالح']);
        }

        return DB::transaction(function () use ($item, $owner, $actor, $choices, $listStores) {
            $stores = [];
            foreach ($item['stores'] as $s) {
                $choice = $choices[$s['name']] ?? null;
                if ($choice === 'ignore') {
                    continue;
                }
                $storeId = $choice ? (int) $choice : $this->matcher->match($s['name']);
                if (! $storeId) {
                    throw ValidationException::withMessages(['store_choices' => "اختر المتجر المقابل لـ «{$s['name']}» أو تجاهله."]);
                }
                if ($choice) {
                    $this->matcher->remember($s['name'], $storeId, $actor);
                }
                $stores[$storeId] = ['store_id' => $storeId, 'shipments_count' => $s['count']];
            }
            $single = count($stores) === 1 ? array_key_first($stores) : null;

            $path = 'imports/legacy/'.Str::uuid().'.txt';
            Storage::disk('private')->put($path, $item['text'] ?? '');
            $batch = ImportBatch::create([
                'original_name' => 'واتساب '.$item['date'].'.txt', 'disk' => 'private', 'stored_path' => $path,
                'file_hash' => $item['hash'], 'file_size' => strlen($item['text'] ?? ''), 'file_type' => 'text',
                'report_type' => 'legacy_whatsapp', 'work_date' => $item['date'], 'uploaded_by' => $actor->id,
                'status' => ImportStatus::NeedsReview, 'total_rows' => 1, 'accepted_rows' => 1,
                'meta' => ['employee_name' => $item['employee_name'] ?? null],
            ]);

            $counts = array_filter(array_column($stores, 'shipments_count'), fn ($c) => $c !== null);
            $report = DailyReport::create([
                'work_date' => Carbon::parse($item['date']), 'user_id' => $owner->id, 'status' => ReportStatus::PendingReview,
                'source' => 'import', 'is_legacy' => true, 'import_batch_id' => $batch->id,
                'submitted_at' => now(), 'total_shipments' => $counts ? array_sum($counts) : null, 'created_by' => $actor->id,
            ]);

            $result = $this->drafts->save($report, [
                'stores' => array_values($stores),
                'unanswered' => [
                    'customer' => implode(' ', $item['customer_codes']), 'customer_store_id' => $listStores['customer'] ?? $single,
                    'courier' => implode(' ', $item['courier_codes']), 'courier_store_id' => $listStores['courier'] ?? $single,
                ],
                'attempt_times' => $item['attempt_times'],
                'attempts_over_limit_reason' => count($item['attempt_times']) > ReportDraftService::maxAttempts() ? 'تقرير قديم مستورد' : null,
                'notes' => $item['notes'],
            ], $actor);

            $batch->update(['daily_report_id' => $report->id]);

            return ['report' => $report->fresh(), 'warnings' => $result['warnings']];
        });
    }
}
