<?php

namespace App\Services\Imports;

use App\Models\Shipment;
use App\Support\ArabicNormalizer;

/**
 * يحوّل صفوف ملف المنظومة (بعد مطابقة الأعمدة) إلى أرقام لكل متجر وللشفت كله.
 * layout=orders: صف لكل طلبية. layout=stores: صف لكل متجر.
 * المتاجر غير المطابَقة تُعرض دون أن تدخل في المجاميع، والمتجاهَلة لا تُعرض.
 */
class ShiftFileCalculator
{
    /**
     * @param  callable(string): (int|string|null)  $resolveStore  معرّف المتجر، أو 'ignore'، أو null إن لم يُطابَق
     */
    public function compute(array $rows, array $mapping, callable $resolveStore): array
    {
        $cols = $mapping['columns'];
        $orders = $mapping['layout'] === 'orders';
        $delivered = array_map(fn ($v) => $this->norm($v), $mapping['delivered_values'] ?? []);

        $stores = [];        // name => [store_id, total, delivered|null]
        $rejected = [];
        $unmatched = [];
        $ignored = 0;
        $statusValues = [];
        $seenCodes = [];

        foreach ($rows as ['row' => $n, 'cells' => $cells]) {
            $name = $this->text($cells[$cols['store']] ?? null);
            if ($name === null) {
                $rejected[] = ['row' => $n, 'reason' => 'المتجر فارغ'];

                continue;
            }

            if ($orders) {
                $status = $this->text($cells[$cols['status']] ?? null);
                if ($status === null) {
                    $rejected[] = ['row' => $n, 'reason' => 'الحالة فارغة'];

                    continue;
                }
                if (! empty($cols['code'])) {
                    $code = Shipment::normalizeCode($cells[$cols['code']] ?? null);
                    if ($code !== null && isset($seenCodes[$code])) {
                        $rejected[] = ['row' => $n, 'reason' => 'كود مكرر'];

                        continue;
                    }
                    if ($code !== null) {
                        $seenCodes[$code] = true;
                    }
                }
                $statusValues[$this->norm($status)] ??= $status;
                $total = 1;
                // بلا حالات «تم التسليم» محددة: المسلَّم غير معروف (لا صفر)
                $done = $delivered === [] ? null : (in_array($this->norm($status), $delivered, true) ? 1 : 0);
            } else {
                $total = $this->int($cells[$cols['total']] ?? null);
                if ($total === null) {
                    $rejected[] = ['row' => $n, 'reason' => 'رقم غير صالح'];

                    continue;
                }
                $done = null;
                if (! empty($cols['delivered']) && ($raw = $cells[$cols['delivered']] ?? null) !== null) {
                    $done = $this->int($raw);
                    if ($done === null) {
                        $rejected[] = ['row' => $n, 'reason' => 'رقم غير صالح'];

                        continue;
                    }
                } elseif (! empty($cols['rate']) && ($raw = $cells[$cols['rate']] ?? null) !== null) {
                    $rate = $this->rate($raw);
                    if ($rate === null) {
                        $rejected[] = ['row' => $n, 'reason' => 'نسبة غير صالحة'];

                        continue;
                    }
                    $done = (int) round($rate * $total / 100);
                }
            }

            $resolved = $resolveStore($name);
            if ($resolved === 'ignore') {
                $ignored++;

                continue;
            }
            if ($resolved === null && ! in_array($name, $unmatched, true)) {
                $unmatched[] = $name;
            }

            $s = $stores[$name] ?? ['store_id' => $resolved, 'total' => 0, 'delivered' => null];
            $s['total'] += $total;
            if ($done !== null) {
                $s['delivered'] = ($s['delivered'] ?? 0) + $done;
            }
            $stores[$name] = $s;
        }

        $list = [];
        $sumTotal = null;
        $sumDone = null;
        foreach ($stores as $name => $s) {
            $list[] = ['name' => $name, 'store_id' => $s['store_id'], 'total' => $s['total'], 'delivered' => $s['delivered'],
                'rate' => $this->pct($s['delivered'], $s['total'])];
            if ($s['store_id'] !== null) {
                $sumTotal = ($sumTotal ?? 0) + $s['total'];
                if ($s['delivered'] !== null) {
                    $sumDone = ($sumDone ?? 0) + $s['delivered'];
                }
            }
        }

        return [
            'stores' => $list,
            'total' => $sumTotal,
            'delivered' => $sumDone,
            'rate' => $sumTotal === null ? null : $this->pct($sumDone, $sumTotal),
            'rejected' => $rejected,
            'unmatched' => $unmatched,
            'ignored' => $ignored,
            'status_values' => array_values($statusValues),
        ];
    }

    private function text(?string $v): ?string
    {
        $v = $v === null ? null : trim(preg_replace('/\s+/u', ' ', $v));

        return $v === '' ? null : $v;
    }

    private function norm(string $v): string
    {
        return ArabicNormalizer::normalize($v);
    }

    private function number(?string $v): ?float
    {
        if ($v === null) {
            return null;
        }
        $v = str_replace(['٬', ' ', '%', '٪'], '', ArabicNormalizer::digits(trim($v)));
        // 1,200 = فاصل آلاف، 0,5 = فاصلة عشرية
        $v = preg_match('/^\d{1,3}(,\d{3})+(\.\d+)?$/', $v) ? str_replace(',', '', $v) : str_replace([',', '٫'], '.', $v);

        return is_numeric($v) ? (float) $v : null;
    }

    private function int(?string $v): ?int
    {
        $n = $this->number($v);

        return ($n === null || $n < 0 || floor($n) != $n) ? null : (int) $n;
    }

    /** "73%" و"0.73" و"73" كلها 73؛ خارج 0–100 مرفوض */
    private function rate(string $raw): ?float
    {
        $n = $this->number($raw);
        if ($n === null || $n < 0) {
            return null;
        }
        if (! str_contains($raw, '%') && ! str_contains($raw, '٪') && $n <= 1) {
            $n *= 100;
        }

        return $n > 100 ? null : $n;
    }

    private function pct(?int $done, int $total): ?float
    {
        return ($done === null || $total === 0) ? null : round($done / $total * 100, 2);
    }
}
