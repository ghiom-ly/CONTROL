<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Services\Imports\HeaderSignature;
use App\Services\Imports\ImportException;
use App\Services\Imports\SpreadsheetReader;
use Tests\Concerns\MakesSpreadsheets;
use Tests\TestCase;

class SpreadsheetReaderTest extends TestCase
{
    use MakesSpreadsheets;

    private function read($file): array
    {
        return app(SpreadsheetReader::class)->read($file->getRealPath());
    }

    public function test_reads_xlsx_headers_and_rows(): void
    {
        $out = $this->read($this->xlsx([
            [null, null],
            ['الكود', 'المتجر'],
            ['NR1', 'متجر الأمل'],
            [55907, 'متجر السلام'],
        ]));
        $this->assertSame(['الكود', 'المتجر'], $out['headers']);
        $this->assertSame([
            ['row' => 3, 'cells' => ['الكود' => 'NR1', 'المتجر' => 'متجر الأمل']],
            ['row' => 4, 'cells' => ['الكود' => '55907', 'المتجر' => 'متجر السلام']],
        ], $out['rows']);
    }

    public function test_reads_csv_utf8_with_bom(): void
    {
        $out = $this->read($this->csv([['المتجر', 'الإجمالي'], ['متجر الأمل', '34']]));
        $this->assertSame(['المتجر', 'الإجمالي'], $out['headers']);
        $this->assertSame('34', $out['rows'][0]['cells']['الإجمالي']);
    }

    public function test_reads_text_numbers_and_skips_blank_rows(): void
    {
        $out = $this->read($this->xlsx([
            ['المتجر', 'العدد', 'نسبة'],
            ['أ', '٣٤', 0.5],
            [null, null, null],
            ['ب', 12.0, ''],
        ]));
        $this->assertCount(2, $out['rows']);
        $this->assertSame('٣٤', $out['rows'][0]['cells']['العدد']);
        $this->assertSame('0.5', $out['rows'][0]['cells']['نسبة']);
        $this->assertSame(4, $out['rows'][1]['row']);
        $this->assertSame('12', $out['rows'][1]['cells']['العدد']);
        $this->assertNull($out['rows'][1]['cells']['نسبة']);
    }

    public function test_duplicate_and_empty_headers_renamed(): void
    {
        $out = $this->read($this->xlsx([['الحالة', null, 'الحالة'], ['a', 'b', 'c']]));
        $this->assertSame(['الحالة', 'عمود 2', 'الحالة (2)'], $out['headers']);
    }

    public function test_too_many_rows_throws(): void
    {
        SystemSetting::where('key', 'import_max_rows')->update(['value' => '3']);
        $this->expectException(ImportException::class);
        $this->read($this->xlsx([['h'], ['1'], ['2'], ['3'], ['4']]));
    }

    public function test_empty_file_throws(): void
    {
        $this->expectException(ImportException::class);
        $this->read($this->xlsx([[null]]));
    }

    public function test_signature_ignores_order_and_alef_variants(): void
    {
        $this->assertSame(HeaderSignature::of(['الإجمالي', 'المتجر']), HeaderSignature::of(['المتجر ', 'الاجمالي']));
        $this->assertNotSame(HeaderSignature::of(['المتجر']), HeaderSignature::of(['المتجر', 'الحالة']));
    }
}
