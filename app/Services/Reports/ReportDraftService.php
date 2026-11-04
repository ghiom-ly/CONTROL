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

    /** «file» إن بقي الرقم كما جاء من الملف، «manual» إن كتبه/عدّله الموظف، null إن كان فارغاً */
    private static function countSource(?int $count, ?ReportStore $old): ?string
    {
        if ($count === null) {
            return null;
        }

        return ($old?->shipments_count_source === 'file' && $old->shipments_count === $count) ? 'file' : 'manual';
    }

    /**
     * أرقام بداية المتجر: المُرسلة في النموذج إن وُجد المفتاح، وإلا تبقى المحفوظة.
     * المصدر يبقى «file» فقط إن لم تتغير القيمتان عمّا جاء من الملف.
     */
    private static function startValues(array $row, ?ReportStore $old): array
    {
        $posted = array_key_exists('start_orders', $row) || array_key_exists('start_rate', $row);
        $orders = $posted ? (isset($row['start_orders']) && $row['start_orders'] !== '' ? (int) $row['start_orders'] : null) : $old?->start_orders;
        $rate = $posted ? (isset($row['start_rate']) && $row['start_rate'] !== '' ? round((float) $row['start_rate'], 2) : null) : ($old?->start_rate === null ? null : (float) $old->start_rate);

        $unchanged = $old && $old->start_orders === $orders && ($old->start_rate === null ? null : (float) $old->start_rate) === $rate;
        $source = ($orders === null && $rate === null) ? null : ($unchanged ? ($old->start_source ?? 'manual') : 'manual');

        return ['start_orders' => $orders, 'start_rate' => $rate, 'start_source' => $source];
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

            // وقت المتابعة يبقى كما سُجّل أول مرة — حتى لا يصبح تعديلٌ لاحق على تقرير قديم «آخر متابعة»
            $oldTimes = $report->followups()->pluck('followup_at', 'shipment_code');
            $newCodeTime = match (true) {
                $report->is_legacy => $report->work_date->copy()->startOfDay(),   // التقرير القديم يُؤرَّخ بيومه
                $report->status === \App\Enums\ReportStatus::Draft => now(),
                default => $report->submitted_at ?? $report->shift_started_at ?? now(),
            };

            // أرقام ملف المنظومة ومصدر كل قيمة تبقى عبر الحفظ (المرحلة 3)
            $oldStores = $report->stores()->get()->keyBy('store_id');

            $report->followups()->delete();
            $report->stores()->delete();

            $reportStores = [];
            foreach ($storeRows as $storeId => $row) {
                $old = $oldStores[$storeId] ?? null;
                $count = isset($row['shipments_count']) && $row['shipments_count'] !== '' ? (int) $row['shipments_count'] : null;
                $reportStores[$storeId] = ReportStore::create([
                    'daily_report_id' => $report->id,
                    'store_id' => $storeId,
                    'is_outside_assignment' => $stores[$storeId]->assigned_user_id !== $report->user_id,
                    'shipments_count' => $count,
                    'shipments_count_source' => self::countSource($count, $old),
                    ...self::startValues($row, $old),
                    'file_orders_count' => $old?->file_orders_count,
                    'file_delivered_count' => $old?->file_delivered_count,
                    'end_file_orders_count' => $old?->end_file_orders_count,
                    'end_file_delivered_count' => $old?->end_file_delivered_count,
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
                    'followup_at' => $oldTimes[(string) $code] ?? $newCodeTime,
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
            \App\Support\StartTotals::refresh($report);

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
                // التقرير القديم يحتفظ بمتجره كما في النص؛ التعارض يصبح تحذيراً (سجل تاريخي)
                $c['store_id'] = $report->is_legacy ? ($c['store_id'] ?? $owner) : ($owner ?? $c['store_id']);
                if ($c['store_id'] === null || ! $stores->has($c['store_id'])) {
                    $errors["unanswered.{$c['from']}_store_id"] = $c['store_id'] === null
                        ? 'اختر المتجر الذي تتبع له الأكواد الملصوقة.'
                        : 'الكود '.$code.' يتبع متجراً غير مضاف في القسم ①.';

                    continue;
                }
            }
            if ($owner !== null && $owner !== $c['store_id']) {
                $msg = 'الكود '.$code.' مسجّل لمتجر '.(Store::find($owner)?->name ?? $owner);
                if ($report->is_legacy) {
                    $warnings[] = $msg.' — سيُحفظ كسجل تاريخي فقط.';
                } else {
                    $errors['codes'] = $msg;
                }

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
