<?php

namespace Tests\Feature;

use App\Models\DailyReport;
use App\Models\ReportStore;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\User;
use Tests\TestCase;

class StoreBulkDeleteTest extends TestCase
{
    private User $boss;

    protected function setUp(): void
    {
        parent::setUp();
        $this->boss = $this->makeUser(Role::SUPER_ADMIN);
    }

    public function test_selected_stores_without_history_are_deleted_permanently(): void
    {
        [$a, $b, $keep] = Store::factory()->count(3)->create();

        $this->actingAs($this->boss)->delete('/stores/bulk', ['store_ids' => [$a->id, $b->id]])
            ->assertRedirect()->assertSessionHas('success', 'تم حذف 2 متجر.');

        $this->assertSame(0, Store::withTrashed()->whereIn('id', [$a->id, $b->id])->count());
        $this->assertTrue($keep->fresh()->exists);
        $this->assertDatabaseHas('audit_logs', ['event' => 'deleted', 'auditable_type' => Store::class, 'auditable_id' => $a->id]);
    }

    public function test_stores_with_history_are_archived_instead(): void
    {
        $withReport = Store::factory()->create();
        $withShipment = Store::factory()->create();
        $clean = Store::factory()->create();
        $rep = DailyReport::factory()->draft()->for($this->makeUser())->create();
        ReportStore::create(['daily_report_id' => $rep->id, 'store_id' => $withReport->id, 'shipments_count' => 3]);
        Shipment::create(['code' => 'BULK-1', 'store_id' => $withShipment->id, 'registered_at' => today(), 'processing_status' => 'open']);

        $this->actingAs($this->boss)->delete('/stores/bulk', ['store_ids' => [$withReport->id, $withShipment->id, $clean->id]])
            ->assertSessionHas('success', 'تم حذف 1 متجر. وتمت أرشفة 2 متجر لأن لها تقارير أو سجلات سابقة.');

        $this->assertSame('archived', $withReport->fresh()->status->value);
        $this->assertSame('archived', $withShipment->fresh()->status->value);
        $this->assertNull(Store::withTrashed()->find($clean->id));
    }

    public function test_all_matching_filter_deletes_only_matching(): void
    {
        Store::factory()->create(['name' => 'Shipper 1']);
        Store::factory()->create(['name' => 'Shipper 2']);
        $other = Store::factory()->create(['name' => 'متجر الأمل']);

        $this->actingAs($this->boss)->delete('/stores/bulk', ['all_matching' => 1, 'q' => 'Shipper'])
            ->assertSessionHas('success', 'تم حذف 2 متجر.');

        $this->assertSame([$other->id], Store::pluck('id')->all());
    }

    public function test_requires_selection(): void
    {
        Store::factory()->create();
        $this->actingAs($this->boss)->delete('/stores/bulk', [])->assertSessionHasErrors('store_ids');
        $this->assertSame(1, Store::count());
    }

    public function test_admin_and_employee_cannot_bulk_delete(): void
    {
        $s = Store::factory()->create();
        $this->actingAs($this->makeUser(Role::ADMIN))->delete('/stores/bulk', ['store_ids' => [$s->id]])->assertForbidden();
        $this->actingAs($this->makeUser())->delete('/stores/bulk', ['store_ids' => [$s->id]])->assertForbidden();
        $this->assertTrue($s->fresh()->exists);
    }

    public function test_index_shows_selection_only_to_super_admin(): void
    {
        Store::factory()->create(['name' => 'متجر ظاهر']);
        $this->actingAs($this->boss)->get('/stores')->assertOk()->assertSee('حذف المحدد')->assertSee('name="store_ids[]"', false);
        $this->actingAs($this->makeUser(Role::ADMIN))->get('/stores')->assertOk()->assertDontSee('حذف المحدد');
    }

    public function test_text_search_does_not_match_every_phone(): void
    {
        Store::factory()->create(['name' => 'Shipper', 'phone' => '0911111111']);
        Store::factory()->create(['name' => 'متجر آخر', 'phone' => '0922222222']);
        $res = $this->actingAs($this->boss)->get('/stores?q=Shipper')->assertOk();
        $this->assertSame(['Shipper'], collect($res->viewData('stores')->items())->pluck('name')->all());
    }

    public function test_per_page_option(): void
    {
        Store::factory()->count(30)->create();
        $res = $this->actingAs($this->boss)->get('/stores?per_page=100')->assertOk();
        $this->assertCount(30, $res->viewData('stores')->items());
    }
}
