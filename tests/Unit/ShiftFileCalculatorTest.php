<?php

namespace Tests\Unit;

use App\Services\Imports\ShiftFileCalculator;
use PHPUnit\Framework\TestCase;

class ShiftFileCalculatorTest extends TestCase
{
    private function rows(array $list): array
    {
        return array_map(fn ($cells, $i) => ['row' => $i + 2, 'cells' => $cells], $list, array_keys($list));
    }

    private function resolver(array $map): callable
    {
        return fn (string $name) => $map[$name] ?? null;
    }

    private function orders(array $rows, array $delivered = ['تم التسليم'], array $map = ['أ' => 1, 'ب' => 2]): array
    {
        return (new ShiftFileCalculator)->compute($this->rows($rows),
            ['layout' => 'orders', 'columns' => ['store' => 'المتجر', 'status' => 'الحالة', 'code' => 'الكود'], 'delivered_values' => $delivered],
            $this->resolver($map));
    }

    public function test_orders_layout_counts_per_store(): void
    {
        $r = $this->orders([
            ['المتجر' => 'أ', 'الحالة' => 'تم التسليم', 'الكود' => 'C1'],
            ['المتجر' => 'أ', 'الحالة' => 'تم التسليم', 'الكود' => 'C2'],
            ['المتجر' => 'أ', 'الحالة' => 'جاري التوصيل', 'الكود' => 'C3'],
            ['المتجر' => 'ب', 'الحالة' => 'راجع', 'الكود' => 'C4'],
        ]);
        $this->assertSame([
            ['name' => 'أ', 'store_id' => 1, 'total' => 3, 'delivered' => 2, 'rate' => 66.67],
            ['name' => 'ب', 'store_id' => 2, 'total' => 1, 'delivered' => 0, 'rate' => 0.0],
        ], $r['stores']);
        $this->assertSame(4, $r['total']);
        $this->assertSame(2, $r['delivered']);
        $this->assertSame(50.0, $r['rate']);
        $this->assertSame(['تم التسليم', 'جاري التوصيل', 'راجع'], $r['status_values']);
    }

    public function test_stores_layout_with_delivered_column(): void
    {
        $r = (new ShiftFileCalculator)->compute($this->rows([
            ['المتجر' => 'أ', 'الإجمالي' => '٣٤', 'المسلم' => '25'],
            ['المتجر' => 'ب', 'الإجمالي' => '21', 'المسلم' => '17'],
            ['المتجر' => 'أ', 'الإجمالي' => '6', 'المسلم' => '5'],
        ]), ['layout' => 'stores', 'columns' => ['store' => 'المتجر', 'total' => 'الإجمالي', 'delivered' => 'المسلم']], $this->resolver(['أ' => 1, 'ب' => 2]));

        $this->assertSame(['name' => 'أ', 'store_id' => 1, 'total' => 40, 'delivered' => 30, 'rate' => 75.0], $r['stores'][0]);
        $this->assertSame(61, $r['total']);
        $this->assertSame(47, $r['delivered']);
        $this->assertSame(77.05, $r['rate']);
    }

    public function test_rate_column_accepts_percent_and_fraction(): void
    {
        $r = (new ShiftFileCalculator)->compute($this->rows([
            ['المتجر' => 'أ', 'الإجمالي' => '100', 'النسبة' => '73%'],
            ['المتجر' => 'ب', 'الإجمالي' => '100', 'النسبة' => '0.73'],
            ['المتجر' => 'ج', 'الإجمالي' => '100', 'النسبة' => '٧٣'],
            ['المتجر' => 'د', 'الإجمالي' => '100', 'النسبة' => '150'],
        ]), ['layout' => 'stores', 'columns' => ['store' => 'المتجر', 'total' => 'الإجمالي', 'rate' => 'النسبة']],
            $this->resolver(['أ' => 1, 'ب' => 2, 'ج' => 3, 'د' => 4]));

        $this->assertSame([73, 73, 73], array_column($r['stores'], 'delivered'));
        $this->assertSame([['row' => 5, 'reason' => 'نسبة غير صالحة']], $r['rejected']);
    }

    public function test_status_values_trimmed_and_normalized(): void
    {
        $r = $this->orders([
            ['المتجر' => 'أ', 'الحالة' => 'تم التسليم ', 'الكود' => 'C1'],
            ['المتجر' => 'أ', 'الحالة' => 'تم  التسليم', 'الكود' => 'C2'],
        ], ['تم التسليم']);
        $this->assertSame(2, $r['delivered']);
        $this->assertSame(['تم التسليم'], $r['status_values']);
    }

    public function test_rejects_rows_with_reasons(): void
    {
        $r = $this->orders([
            ['المتجر' => null, 'الحالة' => 'تم التسليم', 'الكود' => 'C1'],
            ['المتجر' => 'أ', 'الحالة' => null, 'الكود' => 'C2'],
            ['المتجر' => 'أ', 'الحالة' => 'راجع', 'الكود' => 'c3'],
            ['المتجر' => 'أ', 'الحالة' => 'راجع', 'الكود' => 'C3'],
        ]);
        $this->assertSame([
            ['row' => 2, 'reason' => 'المتجر فارغ'],
            ['row' => 3, 'reason' => 'الحالة فارغة'],
            ['row' => 5, 'reason' => 'كود مكرر'],
        ], $r['rejected']);
        $this->assertSame(1, $r['total']);
    }

    public function test_bad_number_rejected_in_stores_layout(): void
    {
        $r = (new ShiftFileCalculator)->compute($this->rows([['المتجر' => 'أ', 'الإجمالي' => 'abc']]),
            ['layout' => 'stores', 'columns' => ['store' => 'المتجر', 'total' => 'الإجمالي']], $this->resolver(['أ' => 1]));
        $this->assertSame([['row' => 2, 'reason' => 'رقم غير صالح']], $r['rejected']);
        $this->assertNull($r['total']);
    }

    public function test_ignored_and_unmatched_excluded_from_totals(): void
    {
        $r = $this->orders([
            ['المتجر' => 'أ', 'الحالة' => 'تم التسليم', 'الكود' => 'C1'],
            ['المتجر' => 'مجهول', 'الحالة' => 'تم التسليم', 'الكود' => 'C2'],
            ['المتجر' => 'مجهول', 'الحالة' => 'تم التسليم', 'الكود' => 'C3'],
            ['المتجر' => 'متجاهل', 'الحالة' => 'تم التسليم', 'الكود' => 'C4'],
        ], ['تم التسليم'], ['أ' => 1, 'متجاهل' => 'ignore']);
        $this->assertSame(['مجهول'], $r['unmatched']);
        $this->assertSame(1, $r['ignored']);
        $this->assertSame(1, $r['total']);
        $this->assertCount(2, $r['stores']);   // أ + مجهول (بلا store_id) للعرض
        $this->assertNull($r['stores'][1]['store_id']);
    }

    public function test_zero_total_gives_null_rate(): void
    {
        $r = (new ShiftFileCalculator)->compute($this->rows([['المتجر' => 'أ', 'الإجمالي' => '0', 'المسلم' => '0']]),
            ['layout' => 'stores', 'columns' => ['store' => 'المتجر', 'total' => 'الإجمالي', 'delivered' => 'المسلم']], $this->resolver(['أ' => 1]));
        $this->assertNull($r['stores'][0]['rate']);
        $this->assertSame(0, $r['total']);
        $this->assertNull($r['rate']);
    }
}
