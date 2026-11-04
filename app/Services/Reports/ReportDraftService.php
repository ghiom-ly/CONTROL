<?php

namespace App\Services\Reports;

use App\Enums\FollowupResult;
use App\Models\DailyReport;
use App\Models\ReportStore;
use App\Models\ShipmentFollowup;
use App\Models\Store;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\CodeParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * حفظ محتوى التقرير (الأقسام ①–④). يستبدل متاجر التقرير ومتابعاته بالكامل في كل حفظ.
 * لا يلمس جدول shipments — الأكواد تبقى نصاً حتى الاعتماد.
 */
class ReportDraftService
{
    public function __construct(private readonly ShipmentCodeGuard $guard) {}

    public static function maxAttempts(): int
    {
        return (int) SystemSetting::get('reports.max_attempts_per_shift', 2);
    }

    /** @return array{warnings: list<string>} */
    public function save(DailyReport $report, array $data, User $actor): array
    {
        return DB::transaction(function () use ($report, $data, $actor) {
            $report = DailyReport::whereKey($report->id)->lockForUpdate()->firstOrFail();

            $storeRows = collect($data['stores'] ?? [])->keyBy('store_id');
            $stores = Store::whereIn('id', $storeRows->keys())->get()->keyBy('id');
            $codes = $this->collectCodes($storeRows->all(), $data['unanswered'] ?? []);
            $warnings = $this->checkOwnership($codes, $stores, $report);

            $report->followups()->delete();
            $report->stores()->delete();

            $reportStores = [];
            foreach ($storeRows as $storeId => $row) {
                $reportStores[$storeId] = ReportStore::create([
                    'daily_report_id' => $report->id,
                    'store_id' => $storeId,
                    'is_outside_assignment' => $stores[$storeId]->assigned_user_id !== $report->user_id,
                    'shipments_count' => $row['shipments_count'] ?? null,
                    'notes' => $row['notes'] ?? null,
                ]);
            }

            foreach ($codes as $code => $c) {
                ShipmentFollowup::create([
                    'daily_report_id' => $report->id,
                    'report_store_id' => $reportStores[$c['store_id']]->id,
                    'store_id' => $c['store_id'],
                    'shipment_code' => (string) $code,
                    'user_id' => $report->user_id,
                    'followup_at' => now(),
                    'result' => $c['result'],
                    'is_official' => false,
                ]);
            }

            $times = array_values($data['attempt_times'] ?? []);
            $report->fill([
                'attempt_times' => $times,
                'contact_attempts_count' => count($times),
                'attempts_over_limit_reason' => count($times) > self::maxAttempts() ? ($data['attempts_over_limit_reason'] ?? null) : null,
                'notes' => $data['notes'] ?? null,
                'inquiries' => $data['inquiries'] ?? null,
                'issue_requests' => array_values($data['issue_requests'] ?? []),
                'updated_by' => $actor->id,
            ])->save();

            return ['warnings' => $warnings];
        });
    }

    /**
     * @return array<string, array{store_id: ?int, result: FollowupResult, from: string}>
     */
    private function collectCodes(array $storeRows, array $unanswered): array
    {
        $codes = [];
        $duplicates = [];

        try {
            foreach ($storeRows as $storeId => $row) {
                foreach ($row['codes'] ?? [] as $entry) {
                    foreach (CodeParser::split($entry['code'] ?? '') as $code) {
                        if (isset($codes[$code])) {
                            $duplicates[] = $code;

                            continue;
                        }
                        $codes[$code] = ['store_id' => (int) $storeId, 'result' => FollowupResult::from($entry['result']), 'from' => 'card'];
                    }
                }
            }

            $seenInLists = [];
            foreach (['customer' => FollowupResult::CustomerNoAnswer, 'courier' => FollowupResult::CourierNoAnswer] as $bucket => $result) {
                $listStore = isset($unanswered["{$bucket}_store_id"]) ? (int) $unanswered["{$bucket}_store_id"] : null;
                foreach (CodeParser::split($unanswered[$bucket] ?? '') as $code) {
                    if (isset($seenInLists[$code])) {
                        $duplicates[] = $code;

                        continue;
                    }
                    $seenInLists[$code] = true;
                    if (isset($codes[$code])) {
                        continue;   // نتيجة بطاقة المتجر هي المعتمدة
                    }
                    $codes[$code] = ['store_id' => $listStore, 'result' => $result, 'from' => $bucket];
                }
            }
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['codes' => $e->getMessage()]);
        }

        if ($duplicates) {
            throw ValidationException::withMessages(['codes' => 'الكود مكرر داخل التقرير: '.implode('، ', array_unique($duplicates))]);
        }

        return $codes;
    }

    /** يطبّق ملكية الكود الرسمية ويجمع التحذيرات. يعدّل store_id للأكواد الملصوقة في القسم ③. */
    private function checkOwnership(array &$codes, $stores, DailyReport $report): array
    {
        $owners = $this->guard->officialOwners(array_map('strval', array_keys($codes)));
        $errors = [];
        $warnings = [];

        foreach ($codes as $code => &$c) {
            $owner = $owners[$code] ?? null;
            if ($c['from'] !== 'card') {
                $c['store_id'] = $owner ?? $c['store_id'];
                if ($c['store_id'] === null || ! $stores->has($c['store_id'])) {
                    $errors["unanswered.{$c['from']}_store_id"] = $c['store_id'] === null
                        ? 'اختر المتجر الذي تتبع له الأكواد الملصوقة.'
                        : 'الكود '.$code.' يتبع متجراً غير مضاف في القسم ①.';

                    continue;
                }
            }
            if ($owner !== null && $owner !== $c['store_id']) {
                $errors['codes'] = 'الكود '.$code.' مسجّل لمتجر '.(Store::find($owner)?->name ?? $owner);

                continue;
            }
            foreach ($this->guard->pendingConflicts((string) $code, $c['store_id'], $report->id) as $other) {
                $warnings[] = "الكود {$code} موجود في التقرير {$other->report_number} لمتجر آخر — سيُحسم عند الاعتماد.";
            }
        }
        unset($c);

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $warnings;
    }
}
