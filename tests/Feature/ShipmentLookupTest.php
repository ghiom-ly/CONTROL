<?php

namespace Tests\Feature;

use App\Models\DailyReport;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\ShipmentFollowup;
use App\Models\Store;
use App\Models\User;
use Tests\TestCase;

class ShipmentLookupTest extends TestCase
{
    private Shipment $ship;

    private User $first;

    private User $last;

    protected function setUp(): void
    {
        parent::setUp();
        $store = Store::factory()->create(['name' => 'متجر الكود']);
        $this->first = $this->makeUser(Role::EMPLOYEE, ['name' => 'موظف أول']);
        $this->last = $this->makeUser(Role::EMPLOYEE, ['name' => 'موظف أخير']);
        $this->ship = Shipment::create(['code' => '4749558', 'store_id' => $store->id, 'registered_at' => today(),
            'processing_status' => 'open', 'last_result' => 'courier_no_answer', 'followups_count' => 2,
            'last_followup_user_id' => $this->last->id, 'last_followup_at' => now()]);
        foreach ([[$this->first, 'customer_no_answer', 2], [$this->last, 'courier_no_answer', 1]] as [$u, $res, $daysAgo]) {
            $r = DailyReport::factory()->approved()->for($u)->create(['work_date' => today()->subDays($daysAgo)]);
            ShipmentFollowup::create(['shipment_id' => $this->ship->id, 'daily_report_id' => $r->id, 'store_id' => $store->id,
                'shipment_code' => '4749558', 'user_id' => $u->id, 'followup_at' => now()->subDays($daysAgo), 'result' => $res, 'is_official' => true]);
        }
    }

    public function test_employee_sees_summary_only(): void
    {
        $this->actingAs($this->makeUser())->get('/shipments/4749558')->assertOk()
            ->assertSee('متجر الكود')->assertSee('موظف أخير')->assertSee('2')
            ->assertDontSee('سجل المتابعات الكامل')->assertDontSee('موظف أول');
    }

    public function test_admin_sees_full_history(): void
    {
        $this->actingAs($this->makeUser(Role::ADMIN))->get('/shipments/4749558')->assertOk()
            ->assertSee('سجل المتابعات الكامل')->assertSee('موظف أول')->assertSee('موظف أخير')
            ->assertSee('https://backoffice.nawris.algoriza.com/orders/4749558', false);
    }

    public function test_lookup_normalizes_code(): void
    {
        $this->actingAs($this->makeUser())->get('/shipments?code='.urlencode(' ٤٧٤٩٥٥٨ '))
            ->assertRedirect(route('shipments.show', '4749558'));
    }

    public function test_unknown_code_page(): void
    {
        $this->actingAs($this->makeUser(Role::ADMIN))->get('/shipments/NOPE1')->assertOk()
            ->assertSee('لا يوجد كود بهذا الرقم')->assertSee('https://backoffice.nawris.algoriza.com/orders/NOPE1', false);
    }

    public function test_search_exact_code_redirects(): void
    {
        $this->actingAs($this->makeUser(Role::ADMIN))->get('/search?q=4749558')->assertRedirect(route('shipments.show', '4749558'));
        $this->actingAs($this->makeUser())->get('/search?q=4749558')->assertRedirect(route('shipments.show', '4749558'));
    }

    public function test_user_without_permissions_forbidden(): void
    {
        $u = $this->makeUser();
        $u->role->permissions()->detach(\App\Models\Permission::where('name', 'shipments.lookup')->value('id'));
        $this->actingAs($u->fresh())->get('/shipments/4749558')->assertForbidden();
    }
}
