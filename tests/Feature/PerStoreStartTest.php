<?php

namespace Tests\Feature;

use App\Models\DailyReport;
use App\Models\ReportStore;
use App\Models\Store;
use App\Models\User;
use App\Services\Imports\ShiftFileImporter;
use App\Support\StartTotals;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesSpreadsheets;
use Tests\TestCase;

class PerStoreStartTest extends TestCase
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

    private function start(array $rows)
    {
        return $this->actingAs($this->emp)->post('/reports/start', ['stores' => $rows]);
    }

    public function test_totals_helper_weighted(): void
    {
        $this->assertSame(['total' => 150, 'rate' => 70.0], StartTotals::compute([['start_orders' => 100, 'start_rate' => 80], ['start_orders' => 50, 'start_rate' => 50]]));
        $this->assertSame(['total' => 10, 'rate' => null], StartTotals::compute([['start_orders' => 10, 'start_rate' => null]]));
        $this->assertSame(['total' => null, 'rate' => null], StartTotals::compute([]));
    }

    public function test_start_page_lists_assigned_stores(): void
    {
        Store::factory()->create(['name' => 'متجر لزميل']);
        $this->actingAs($this->emp)->get('/reports/new')->assertOk()
            ->assertSee('متجر الأمل')->assertSee('متجر السلام');
    }

    public function test_start_with_per_store_values_computes_weighted_rate(): void
    {
        $this->start([
            ['store_id' => $this->a->id, 'start_orders' => '١٠٠', 'start_rate' => '80'],
            ['store_id' => $this->b->id, 'start_orders' => 50, 'start_rate' => 50],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $r = DailyReport::sole();
        $this->assertSame(150, $r->start_total_orders);
        $this->assertSame('70.00', $r->start_delivery_rate);
        $rs = $r->stores()->orderBy('store_id')->get();
        $this->assertSame([100, 50], $rs->pluck('start_orders')->all());
        $this->assertSame(['manual', 'manual'], $rs->pluck('start_source')->all());
        $this->assertSame([null, null], $rs->pluck('shipments_count')->all(), '«اشتغلت عليها» رقم مختلف يكتبه لاحقاً');
    }

    public function test_empty_rows_skipped_and_one_required(): void
    {
        $this->start([['store_id' => $this->a->id, 'start_orders' => '', 'start_rate' => ''], ['store_id' => $this->b->id]])
            ->assertSessionHasErrors('stores');
        $this->assertSame(0, DailyReport::count());

        $this->start([['store_id' => $this->a->id, 'start_orders' => 20, 'start_rate' => 60], ['store_id' => $this->b->id, 'start_orders' => '', 'start_rate' => '']]);
        $this->assertSame([$this->a->id], DailyReport::sole()->stores()->pluck('store_id')->all());
    }

    public function test_rate_over_100_rejected(): void
    {
        $this->start([['store_id' => $this->a->id, 'start_orders' => 20, 'start_rate' => 120]])->assertSessionHasErrors('stores.0.start_rate');
    }

    public function test_edit_start_values_recomputes(): void
    {
        $this->start([['store_id' => $this->a->id, 'start_orders' => 100, 'start_rate' => 80]]);
        $r = DailyReport::sole();
        $this->actingAs($this->emp)->put("/reports/{$r->id}", ['stores' => [
            ['store_id' => $this->a->id, 'start_orders' => 100, 'start_rate' => 90, 'shipments_count' => 30],
            ['store_id' => $this->b->id, 'start_orders' => 100, 'start_rate' => 70],
        ]])->assertSessionHasNoErrors();
        $r->refresh();
        $this->assertSame(200, $r->start_total_orders);
        $this->assertSame('80.00', $r->start_delivery_rate);
        $this->assertSame(30, $r->stores()->where('store_id', $this->a->id)->value('shipments_count'));
    }

    private function fileBatch(array $stores, ?DailyReport $r = null)
    {
        $rows = [['المتجر', 'الإجمالي', 'المسلم']];
        foreach ($stores as [$n, $t, $d]) {
            $rows[] = [$n, $t, $d];
        }
        $svc = app(ShiftFileImporter::class);
        $b = $svc->upload($this->xlsx($rows), 'shift_start', $r, $this->emp)['batch'];
        $svc->saveMapping($b, ['layout' => 'stores', 'columns' => ['store' => 'المتجر', 'total' => 'الإجمالي', 'delivered' => 'المسلم']], []);

        return $b->fresh();
    }

    public function test_file_fills_start_values_not_worked_count(): void
    {
        $this->actingAs($this->emp)->post('/imports/'.$this->fileBatch([['متجر الأمل', 40, 30], ['متجر السلام', 10, 5]])->id.'/apply');
        $r = DailyReport::sole();
        $a = $r->stores()->where('store_id', $this->a->id)->sole();
        $this->assertSame(40, $a->start_orders);
        $this->assertSame('75.00', $a->start_rate);
        $this->assertSame('file', $a->start_source);
        $this->assertNull($a->shipments_count);
        $this->assertSame(50, $r->start_total_orders);
        $this->assertSame('70.00', $r->start_delivery_rate);
    }

    public function test_manual_start_value_not_overwritten_by_file(): void
    {
        $this->start([['store_id' => $this->a->id, 'start_orders' => 33, 'start_rate' => 60]]);
        $r = DailyReport::sole();
        $this->actingAs($this->emp)->post('/imports/'.$this->fileBatch([['متجر الأمل', 40, 30], ['متجر السلام', 10, 5]], $r)->id.'/apply');
        $a = $r->stores()->where('store_id', $this->a->id)->sole();
        $this->assertSame(33, $a->start_orders);
        $this->assertSame(40, $a->file_orders_count);
        $this->assertSame(10, $r->stores()->where('store_id', $this->b->id)->value('start_orders'));
    }

    public function test_old_report_without_store_values_keeps_totals(): void
    {
        $r = DailyReport::factory()->draft()->for($this->emp)->create(['start_total_orders' => 99, 'start_delivery_rate' => 55]);
        ReportStore::create(['daily_report_id' => $r->id, 'store_id' => $this->a->id]);
        $this->actingAs($this->emp)->put("/reports/{$r->id}", ['stores' => [['store_id' => $this->a->id, 'shipments_count' => 5]]])->assertSessionHasNoErrors();
        $this->assertSame(99, $r->fresh()->start_total_orders);
        $this->assertSame('55.00', $r->fresh()->start_delivery_rate);
    }

    public function test_discrepancy_per_store_rate(): void
    {
        $this->actingAs($this->emp)->post('/imports/'.$this->fileBatch([['متجر الأمل', 40, 30]])->id.'/apply');
        $r = DailyReport::sole();
        $this->actingAs($this->emp)->put("/reports/{$r->id}", ['stores' => [['store_id' => $this->a->id, 'start_orders' => 40, 'start_rate' => 90]]]);
        $labels = array_column($r->fresh()->fileDiscrepancies(), 'label');
        $this->assertContains('نسبة البداية — متجر الأمل', $labels);
        $this->assertNotContains('طلبيات البداية — متجر الأمل', $labels);
    }

    public function test_whatsapp_text_lists_start_per_store(): void
    {
        $this->start([['store_id' => $this->a->id, 'start_orders' => 40, 'start_rate' => 75]]);
        $this->assertStringContainsString('- متجر الأمل: 40 طلبية — 75%', \App\Support\ReportWhatsappFormatter::format(DailyReport::sole()));
    }
}
