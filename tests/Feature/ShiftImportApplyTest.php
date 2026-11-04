<?php

namespace Tests\Feature;

use App\Models\DailyReport;
use App\Models\ImportBatch;
use App\Models\ImportMapping;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreAlias;
use App\Models\User;
use App\Services\Imports\ShiftFileImporter;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesSpreadsheets;
use Tests\TestCase;

class ShiftImportApplyTest extends TestCase
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
        $this->b = Store::factory()->create(['name' => 'متجر السلام']);
    }

    /** ملف ملخّص: [اسم => [إجمالي، مسلَّم]] */
    private function batch(array $stores, string $kind = 'shift_start', ?DailyReport $report = null, array $choices = []): ImportBatch
    {
        $rows = [['المتجر', 'الإجمالي', 'المسلم']];
        foreach ($stores as $name => [$t, $d]) {
            $rows[] = [$name, $t, $d];
        }
        $svc = app(ShiftFileImporter::class);
        $b = $svc->upload($this->xlsx($rows), $kind, $report, $this->emp)['batch'];
        $svc->saveMapping($b, ['layout' => 'stores', 'columns' => ['store' => 'المتجر', 'total' => 'الإجمالي', 'delivered' => 'المسلم']], $choices);

        return $b->fresh();
    }

    private function apply(ImportBatch $b, array $extra = [])
    {
        return $this->actingAs($this->emp)->post("/imports/{$b->id}/apply", $extra);
    }

    public function test_start_file_creates_shift_and_fills_report(): void
    {
        $b = $this->batch(['متجر الأمل' => [30, 24], 'السلام فرع' => [10, 6]], 'shift_start', null, ['السلام فرع' => $this->b->id]);
        $this->apply($b)->assertRedirect();

        $r = DailyReport::sole();
        $this->assertSame('draft', $r->status->value);
        $this->assertSame(40, $r->start_total_orders);
        $this->assertSame('75.00', $r->start_delivery_rate);
        $this->assertSame(['start_total_orders' => 'file', 'start_delivery_rate' => 'file'], $r->value_sources);
        $this->assertSame(40, $r->file_values['start_total']);
        $rs = $r->stores()->orderBy('store_id')->get();
        $this->assertSame([30, 10], $rs->pluck('shipments_count')->all());
        $this->assertSame(['file', 'file'], $rs->pluck('shipments_count_source')->all());
        $this->assertSame([24, 6], $rs->pluck('file_delivered_count')->all());
        $this->assertTrue($rs->firstWhere('store_id', $this->b->id)->is_outside_assignment);

        $b->refresh();
        $this->assertSame('needs_review', $b->status->value);
        $this->assertSame($r->id, $b->daily_report_id);
        $this->assertSame(2, $b->accepted_rows);
        $this->assertSame(1, ImportMapping::count());
        $this->assertSame($this->b->id, StoreAlias::sole()->store_id);
    }

    public function test_start_file_on_open_draft_fills_only_empty_values(): void
    {
        $r = DailyReport::factory()->draft()->for($this->emp)->create(['start_total_orders' => 99, 'start_delivery_rate' => 50]);
        $this->actingAs($this->emp)->put("/reports/{$r->id}", ['stores' => [['store_id' => $this->a->id, 'shipments_count' => 7]]]);

        $this->apply($this->batch(['متجر الأمل' => [30, 24], 'متجر السلام' => [10, 6]], 'shift_start', $r))->assertSessionHasNoErrors();

        $r->refresh();
        $this->assertSame(99, $r->start_total_orders, 'رقم الموظف لا يُستبدل');
        $this->assertSame(40, $r->file_values['start_total']);
        $this->assertSame(7, $r->stores()->where('store_id', $this->a->id)->value('shipments_count'));
        $this->assertSame(30, $r->stores()->where('store_id', $this->a->id)->value('file_orders_count'));
        $this->assertSame(10, $r->stores()->where('store_id', $this->b->id)->value('shipments_count'));
    }

    public function test_second_file_keeps_manual_values(): void
    {
        $this->apply($this->batch(['متجر الأمل' => [30, 24], 'متجر السلام' => [10, 6]]));
        $r = DailyReport::sole();
        // الموظف يعدّل متجر الأمل يدوياً ويُبقي السلام كما جاء من الملف
        $this->actingAs($this->emp)->put("/reports/{$r->id}", ['stores' => [
            ['store_id' => $this->a->id, 'shipments_count' => 28], ['store_id' => $this->b->id, 'shipments_count' => 10],
        ]])->assertSessionHasNoErrors();
        $this->assertSame('manual', $r->stores()->where('store_id', $this->a->id)->value('shipments_count_source'));
        $this->assertSame('file', $r->stores()->where('store_id', $this->b->id)->value('shipments_count_source'));

        $this->apply($this->batch(['متجر الأمل' => [35, 30], 'متجر السلام' => [12, 9]], 'shift_start', $r));

        $this->assertSame(28, $r->stores()->where('store_id', $this->a->id)->value('shipments_count'));
        $this->assertSame(35, $r->stores()->where('store_id', $this->a->id)->value('file_orders_count'));
        $this->assertSame(12, $r->stores()->where('store_id', $this->b->id)->value('shipments_count'));
        $this->assertSame(47, $r->fresh()->start_total_orders);
    }

    public function test_end_file_fills_end_rate_only(): void
    {
        $this->apply($this->batch(['متجر الأمل' => [30, 24]]));
        $r = DailyReport::sole();
        $this->apply($this->batch(["متجر الأمل" => [30, 27], "متجر السلام" => [5, 5]], "shift_end", $r))->assertRedirect()->assertSessionHasNoErrors();

        $r->refresh();
        $this->assertSame('91.43', $r->end_delivery_rate);
        $this->assertSame('file', $r->value_sources['end_delivery_rate']);
        $this->assertSame(1, $r->stores()->count(), 'ملف النهاية لا يضيف متاجر');
        $this->assertSame(27, $r->stores()->sole()->end_file_delivered_count);
        $this->assertSame(30, $r->start_total_orders);
    }

    public function test_end_file_replaces_manual_rate_only_when_confirmed(): void
    {
        $r = DailyReport::factory()->draft()->for($this->emp)->create(['end_delivery_rate' => 60, 'value_sources' => ['end_delivery_rate' => 'manual']]);
        $this->apply($this->batch(['متجر الأمل' => [10, 8]], 'shift_end', $r));
        $this->assertSame('60.00', $r->fresh()->end_delivery_rate);

        $this->apply($this->batch(['متجر الأمل' => [10, 9]], 'shift_end', $r), ['replace_end_rate' => 1]);
        $this->assertSame('90.00', $r->fresh()->end_delivery_rate);
    }

    public function test_manual_end_rate_differs_is_discrepancy(): void
    {
        $this->apply($this->batch(['متجر الأمل' => [30, 24]]));
        $r = DailyReport::sole();
        $this->apply($this->batch(['متجر الأمل' => [30, 27]], 'shift_end', $r));
        $this->actingAs($this->emp)->put("/reports/{$r->id}", [
            'stores' => [['store_id' => $this->a->id, 'shipments_count' => 30]], 'action' => 'submit', 'end_delivery_rate' => 95,
        ])->assertSessionHasNoErrors();

        $r->refresh();
        $this->assertSame('manual', $r->value_sources['end_delivery_rate']);
        $this->assertContains('file_mismatch', $r->warningFlags());
        $this->assertSame([['label' => 'نسبة التسليم النهائية', 'report' => 95.0, 'file' => 90.0]], $r->fileDiscrepancies());
    }

    public function test_no_files_no_discrepancy_flag(): void
    {
        $r = DailyReport::factory()->draft()->for($this->emp)->create(['start_total_orders' => 5]);
        $this->assertSame([], $r->fileDiscrepancies());
        $this->assertNotContains('file_mismatch', $r->warningFlags());
    }

    public function test_discrepancy_shown_on_review_page(): void
    {
        $this->apply($this->batch(['متجر الأمل' => [30, 24]]));
        $r = DailyReport::sole();
        $this->actingAs($this->emp)->put("/reports/{$r->id}", [
            'stores' => [['store_id' => $this->a->id, 'shipments_count' => 25]], 'action' => 'submit', 'end_delivery_rate' => 80,
        ]);
        $this->actingAs($this->makeUser(Role::ADMIN))->get("/reports/{$r->id}")->assertOk()
            ->assertSee('الفروق مع ملف المنظومة')->assertSee('متجر الأمل');
        $this->actingAs($this->makeUser(Role::ADMIN))->get('/review')->assertSee('فرق مع ملف المنظومة');
    }

    public function test_apply_twice_not_allowed(): void
    {
        $b = $this->batch(['متجر الأمل' => [30, 24]]);
        $this->apply($b);
        $this->apply($b)->assertForbidden();
        $this->assertSame(1, DailyReport::count());
    }

    public function test_apply_requires_mapping_and_numbers(): void
    {
        $svc = app(ShiftFileImporter::class);
        $b = $svc->upload($this->xlsx([['x'], ['y']]), 'shift_start', null, $this->emp)['batch'];
        $this->apply($b)->assertSessionHasErrors('apply');
        $this->assertSame(0, DailyReport::count());
    }
}
