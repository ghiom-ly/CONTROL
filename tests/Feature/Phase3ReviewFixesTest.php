<?php

namespace Tests\Feature;

use App\Models\DailyReport;
use App\Models\ImportBatch;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreAlias;
use App\Models\User;
use App\Services\Imports\LegacyReportParser;
use App\Services\Imports\ShiftFileCalculator;
use App\Services\Imports\ShiftFileImporter;
use App\Services\Imports\SpreadsheetReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesSpreadsheets;
use Tests\TestCase;

/** اختبارات تثبّت ملاحظات المراجعة النهائية للمرحلة 3 */
class Phase3ReviewFixesTest extends TestCase
{
    use MakesSpreadsheets;

    private User $emp;

    private Store $a;

    private Store $b;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        $this->emp = $this->makeUser();
        $this->a = Store::factory()->assignedTo($this->emp->id)->create(['name' => 'متجر الأمل']);
        $this->b = Store::factory()->assignedTo($this->emp->id)->create(['name' => 'متجر السلام']);
    }

    private function batch(array $stores, string $kind = 'shift_start', ?DailyReport $report = null): ImportBatch
    {
        $rows = [['المتجر', 'الإجمالي', 'المسلم']];
        foreach ($stores as [$name, $t, $d]) {
            $rows[] = [$name, $t, $d];
        }
        $svc = app(ShiftFileImporter::class);
        $b = $svc->upload($this->xlsx($rows), $kind, $report, $this->emp)['batch'];
        $svc->saveMapping($b, ['layout' => 'stores', 'columns' => ['store' => 'المتجر', 'total' => 'الإجمالي', 'delivered' => 'المسلم']], []);

        return $b->fresh();
    }

    // #1
    public function test_cannot_apply_to_submitted_or_approved_report(): void
    {
        $r = DailyReport::factory()->draft()->for($this->emp)->create();
        $b = $this->batch([['متجر الأمل', 10, 8]], 'shift_start', $r);
        $r->update(['status' => 'approved']);

        $this->actingAs($this->emp)->post("/imports/{$b->id}/apply")->assertForbidden();
        $this->assertSame(0, $r->stores()->count());
        $this->assertSame('uploaded', $b->fresh()->status->value);
    }

    public function test_submitting_cancels_unapplied_batches(): void
    {
        $r = DailyReport::factory()->draft()->for($this->emp)->create();
        $this->actingAs($this->emp)->put("/reports/{$r->id}", ['stores' => [['store_id' => $this->a->id]]]);
        $b = $this->batch([['متجر الأمل', 10, 8]], 'shift_start', $r);
        $this->actingAs($this->emp)->post("/reports/{$r->id}/submit", ['end_delivery_rate' => 50])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $b->fresh()->status->value);
    }

    // #2
    public function test_orders_layout_without_delivered_values_gives_null_rate(): void
    {
        $rows = [['row' => 2, 'cells' => ['S' => 'أ', 'H' => 'تم']], ['row' => 3, 'cells' => ['S' => 'أ', 'H' => 'راجع']]];
        $r = (new ShiftFileCalculator)->compute($rows, ['layout' => 'orders', 'columns' => ['store' => 'S', 'status' => 'H'], 'delivered_values' => []], fn () => 1);
        $this->assertSame(2, $r['total']);
        $this->assertNull($r['delivered']);
        $this->assertNull($r['rate']);
    }

    // #3
    public function test_two_names_for_same_store_are_summed(): void
    {
        StoreAlias::create(['store_id' => $this->a->id, 'alias' => 'الأمل فرع', 'normalized_alias' => 'الامل فرع']);
        $this->actingAs($this->emp)->post('/imports/'.$this->batch([['متجر الأمل', 30, 20], ['الأمل فرع', 10, 5]])->id.'/apply');

        $rs = DailyReport::sole()->stores()->sole();
        $this->assertSame(40, $rs->file_orders_count);
        $this->assertSame(25, $rs->file_delivered_count);
        $this->assertSame(40, $rs->start_orders);
    }

    // #4
    public function test_second_start_file_clears_stores_it_no_longer_has(): void
    {
        $this->actingAs($this->emp)->post('/imports/'.$this->batch([['متجر الأمل', 30, 20], ['متجر السلام', 10, 5]])->id.'/apply');
        $r = DailyReport::sole();
        $this->actingAs($this->emp)->post('/imports/'.$this->batch([['متجر الأمل', 35, 30]], 'shift_start', $r)->id.'/apply');

        $salam = $r->stores()->where('store_id', $this->b->id)->sole();
        $this->assertNull($salam->file_orders_count);
        $this->assertNull($salam->shipments_count, 'رقم جاء من الملف السابق يُمسح');
        $this->assertSame(35, $r->fresh()->start_total_orders);
    }

    // #5
    public function test_arabic_percent_and_thousands_separators(): void
    {
        $rows = [
            ['row' => 2, 'cells' => ['S' => 'أ', 'T' => '1,200', 'R' => '٧٣٪']],
            ['row' => 3, 'cells' => ['S' => 'ب', 'T' => '١٬٠٠٠', 'R' => '50%']],
        ];
        $r = (new ShiftFileCalculator)->compute($rows, ['layout' => 'stores', 'columns' => ['store' => 'S', 'total' => 'T', 'rate' => 'R']],
            fn ($n) => $n === 'أ' ? 1 : 2);
        $this->assertSame([], $r['rejected']);
        $this->assertSame([1200, 1000], array_column($r['stores'], 'total'));
        $this->assertSame([876, 500], array_column($r['stores'], 'delivered'));
    }

    // #6
    public function test_reads_windows_arabic_csv(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cp').'.csv';
        file_put_contents($path, iconv('UTF-8', 'CP1256', "المتجر,الإجمالي\r\nمتجر الأمل,34\r\n"));
        $out = app(SpreadsheetReader::class)->read($path);
        $this->assertSame(['المتجر', 'الإجمالي'], $out['headers']);
        $this->assertSame('متجر الأمل', $out['rows'][0]['cells']['المتجر']);
    }

    // #7
    public function test_parser_numbered_stores_units_and_multiline_codes(): void
    {
        $text = "ريبورت تاريخ: 2026-10-20\n1) ملخص حركة الأوردرات:\n1- متجر الأمل: 34\n2- متجر النور: 15 طلب\n3) الأكواد غير المُجاب عليها:\n- أكواد لم يرد عليها الزبون:\nA1\nA2\n- أكواد لم يرد عليها المندوب: B1";
        [$r] = (new LegacyReportParser)->parse($text);
        $this->assertSame([['name' => 'متجر الأمل', 'count' => 34], ['name' => 'متجر النور', 'count' => 15]], $r['stores']);
        $this->assertSame(['A1', 'A2'], $r['customer_codes']);
        $this->assertSame(['B1'], $r['courier_codes']);
    }

    // #9
    public function test_rejected_legacy_report_resubmits_without_end_rate(): void
    {
        $r = DailyReport::factory()->rejected()->for($this->emp)->create(['is_legacy' => true, 'source' => 'import']);
        $this->actingAs($this->emp)->put("/reports/{$r->id}", ['stores' => [['store_id' => $this->a->id, 'shipments_count' => 5]], 'action' => 'submit'])
            ->assertSessionHasNoErrors();
        $r->refresh();
        $this->assertSame('pending_review', $r->status->value);
        $this->assertNull($r->end_delivery_rate);
    }

    // #10
    public function test_legacy_store_choice_must_be_existing_active_store(): void
    {
        $text = "ريبورت تاريخ: 2026-10-20\n1) ملخص:\n- متجر غريب: 3";
        $admin = $this->makeUser(Role::ADMIN);
        $this->actingAs($admin)->post('/imports/legacy/preview', ['text' => $text]);
        $archived = Store::factory()->create(['status' => 'archived']);
        foreach ([999999, $archived->id] as $bad) {
            $this->actingAs($admin)->post('/imports/legacy', ['index' => 0, 'user_id' => $this->emp->id, 'store_choices' => ['متجر غريب' => $bad]])
                ->assertSessionHasErrors('store_choices.متجر غريب');
        }
        $this->assertSame(0, DailyReport::count());
    }

    // #12
    public function test_legacy_report_is_marked_for_reviewer(): void
    {
        $r = DailyReport::factory()->pendingReview()->for($this->emp)->create(['is_legacy' => true, 'source' => 'import']);
        $admin = $this->makeUser(Role::ADMIN);
        $this->actingAs($admin)->get("/reports/{$r->id}")->assertSee('تقرير قديم مستورد');
        $this->actingAs($admin)->get('/review')->assertSee('تقرير قديم مستورد');
    }
}
