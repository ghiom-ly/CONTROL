<?php

namespace App\Services\Reports;

use App\Enums\ConfirmationResult;
use App\Models\ConfirmationOrder;
use App\Models\DailyReport;
use App\Models\Shipment;
use App\Models\User;
use App\Support\CancelReasons;
use App\Support\CodeParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** حفظ تقرير التأكيد: يستبدل طلبات التقرير بما في النموذج (الكود فريد داخل التقرير) */
class ConfirmationDraftService
{
    /** تقسيم الخانة بلا حذف للتكرار — التكرار يُكتشف ويُرفض حتى تطابق الأعداد ما تراه الموظفة */
    private static function tokens(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[\s,،;؛]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY) as $part) {
            $code = Shipment::normalizeCode($part);
            if ($code === null) {
                continue;
            }
            if (mb_strlen($code) > CodeParser::MAX_LENGTH) {
                throw new \InvalidArgumentException('الكود أطول من '.CodeParser::MAX_LENGTH.' حرفاً: '.mb_substr($code, 0, 20).'…');
            }
            $out[] = $code;
        }

        return $out;
    }

    public function save(DailyReport $report, array $data, User $actor): void
    {
        $orders = [];
        $dupes = [];
        $add = function (int $storeId, string $raw, ConfirmationResult $result, ?string $reason = null) use (&$orders, &$dupes) {
            $code = Shipment::normalizeCode($raw);
            if ($code === null) {
                return;
            }
            if (isset($orders[$code])) {
                $dupes[] = $code;

                return;
            }
            $orders[$code] = ['store_id' => $storeId, 'result' => $result, 'cancel_reason' => $reason];
        };

        try {
            foreach ($data['stores'] ?? [] as $s) {
                $sid = (int) $s['store_id'];
                foreach (self::tokens((string) ($s['confirmed'] ?? '')) as $c) {
                    $add($sid, $c, ConfirmationResult::Confirmed);
                }
                foreach ($s['cancelled'] ?? [] as $c) {
                    $reason = $c['reason'] === CancelReasons::OTHER ? CancelReasons::OTHER.': '.trim((string) $c['other']) : $c['reason'];
                    $add($sid, (string) $c['code'], ConfirmationResult::Cancelled, mb_substr($reason, 0, 150));
                }
                foreach (self::tokens((string) ($s['no_answer'] ?? '')) as $c) {
                    $add($sid, $c, ConfirmationResult::NoAnswer);
                }
            }
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['orders' => $e->getMessage()]);
        }

        if ($dupes) {
            throw ValidationException::withMessages(['orders' => 'الكود مكرر داخل التقرير: '.implode('، ', array_unique($dupes))]);
        }

        DB::transaction(function () use ($report, $data, $actor, $orders) {
            $report = DailyReport::whereKey($report->id)->lockForUpdate()->firstOrFail();
            $report->confirmationOrders()->delete();
            $now = now();
            foreach (array_chunk(array_keys($orders), 500) as $chunk) {
                ConfirmationOrder::insert(array_map(fn ($code) => [
                    'daily_report_id' => $report->id, 'store_id' => $orders[$code]['store_id'], 'user_id' => $report->user_id,
                    'code' => $code, 'result' => $orders[$code]['result']->value, 'cancel_reason' => $orders[$code]['cancel_reason'],
                    'created_at' => $now, 'updated_at' => $now,
                ], $chunk));
            }
            $report->fill([
                'attempt_times' => $data['attempt_times'] ?? [],
                'notes' => $data['notes'] ?? null,
                'updated_by' => $actor->id,
            ])->save();
        });
    }
}
