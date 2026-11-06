<?php

namespace Tests\Feature;

use App\Models\DailyReport;
use App\Models\Role;
use App\Models\Store;
use Tests\TestCase;

class StoreServiceTypeTest extends TestCase
{
    public function test_default_both_and_editable(): void
    {
        $boss = $this->makeUser(Role::SUPER_ADMIN);
        $this->actingAs($boss)->post('/stores', ['name' => 'متجر تأكيد', 'status' => 'active', 'service_type' => 'confirmation'])->assertSessionHasNoErrors();
        $this->assertSame('confirmation', Store::where('name', 'متجر تأكيد')->value('service_type'));
        $this->assertSame('both', Store::factory()->create()->fresh()->service_type);
        $this->actingAs($boss)->post('/stores', ['name' => 'x', 'status' => 'active', 'service_type' => 'bad'])->assertSessionHasErrors('service_type');
    }

    public function test_followup_report_only_accepts_followup_stores(): void
    {
        $emp = $this->makeUser();
        $conf = Store::factory()->assignedTo($emp->id)->create(['name' => 'متجر تأكيد فقط', 'service_type' => 'confirmation']);
        $fol = Store::factory()->assignedTo($emp->id)->create(['name' => 'متجر متابعة فقط', 'service_type' => 'followup']);

        $this->actingAs($emp)->get('/reports/new')->assertSee('متجر متابعة فقط')->assertDontSee('متجر تأكيد فقط');
        $this->actingAs($emp)->post('/reports/start', ['stores' => [['store_id' => $conf->id, 'start_orders' => 5, 'start_rate' => 50]]])
            ->assertSessionHasErrors('stores.0.store_id');
        $this->actingAs($emp)->post('/reports/start', ['stores' => [['store_id' => $fol->id, 'start_orders' => 5, 'start_rate' => 50]]])->assertSessionHasNoErrors();
        $r = DailyReport::sole();
        $this->actingAs($emp)->put("/reports/{$r->id}", ['stores' => [['store_id' => $conf->id, 'shipments_count' => 1]]])->assertSessionHasErrors('stores.0.store_id');
    }

    public function test_confirmation_report_only_accepts_confirmation_stores(): void
    {
        $emp = $this->makeUser(Role::EMPLOYEE, ['work_type' => 'confirmation']);
        $fol = Store::factory()->create(['name' => 'متجر متابعة فقط', 'service_type' => 'followup']);
        $both = Store::factory()->create(['name' => 'متجر الاثنين', 'service_type' => 'both']);
        $this->actingAs($emp)->post('/reports/start', []);
        $r = DailyReport::sole();
        $this->actingAs($emp)->get("/reports/{$r->id}/edit")->assertSee($both->code)->assertDontSee($fol->code);
        $this->actingAs($emp)->put("/reports/{$r->id}/confirmation", ['stores' => [['store_id' => $fol->id, 'confirmed' => 'A1']]])
            ->assertSessionHasErrors('stores.0.store_id');
    }
}
