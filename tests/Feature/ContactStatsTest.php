<?php

namespace Tests\Feature;

use App\Models\ContactAttempt;
use App\Models\DailyReport;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\ShipmentFollowup;
use App\Models\Store;
use App\Models\User;
use App\Services\ContactStatsService;
use Tests\TestCase;

class ContactStatsTest extends TestCase
{
    private User $emp;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->emp = $this->makeUser(Role::EMPLOYEE, ['name' => 'موظف الإحصاء']);
        $this->store = Store::factory()->create(['name' => 'متجر الإحصاء']);
    }

    private function followup(string $code, string $result, bool $official = true): void
    {
        $r = DailyReport::factory()->approved()->for($this->emp)->create();
        $s = Shipment::create(['code' => $code, 'store_id' => $this->store->id, 'registered_at' => today(), 'processing_status' => 'open']);
        ShipmentFollowup::create(['shipment_id' => $s->id, 'daily_report_id' => $r->id, 'store_id' => $this->store->id, 'shipment_code' => $code,
            'user_id' => $this->emp->id, 'followup_at' => now(), 'result' => $result, 'is_official' => $official]);
    }

    private function manual(User $u, string $party, string $result, ?Store $store = null): void
    {
        ContactAttempt::create(['party_type' => $party, 'store_id' => $store?->id, 'user_id' => $u->id, 'contacted_at' => now(),
            'attempt_number' => 1, 'result' => $result]);
    }

    public function test_counts_from_official_followups_and_manual(): void
    {
        $this->followup('S1', 'customer_no_answer');
        $this->followup('S2', 'courier_promised_delivery');
        $this->followup('S3', 'customer_no_answer', official: false);
        $this->manual($this->emp, 'customer', 'answered');

        $row = app(ContactStatsService::class)->perEmployee(today()->subDays(6), today())->firstWhere('user.id', $this->emp->id);
        $this->assertSame(3, $row['total']);
        $this->assertSame(2, $row['customer']);
        $this->assertSame(1, $row['customer_answered']);
        $this->assertSame(50.0, $row['customer_rate']);
        $this->assertSame(1, $row['courier']);
        $this->assertSame(100.0, $row['courier_rate']);
    }

    public function test_rate_is_null_when_no_attempts(): void
    {
        $this->manual($this->emp, 'courier', 'wrong_number');
        $row = app(ContactStatsService::class)->perEmployee(today()->subDays(6), today())->firstWhere('user.id', $this->emp->id);
        $this->assertSame(1, $row['total']);
        $this->assertNull($row['customer_rate']);
        $this->assertNull($row['courier_rate']);
        $this->actingAs($this->makeUser(Role::ADMIN))->get('/contacts')->assertOk()->assertSee('موظف الإحصاء')->assertSee('—');
    }

    public function test_store_stats(): void
    {
        $admin = $this->makeUser(Role::ADMIN);
        $this->manual($admin, 'store', 'answered', $this->store);
        $this->travel(1)->hours();
        $this->manual($admin, 'store', 'no_answer', $this->store);

        $row = app(ContactStatsService::class)->perStore(today()->subDays(6), today())->firstWhere('store.id', $this->store->id);
        $this->assertSame(2, $row['count']);
        $this->assertTrue($row['last_at']->equalTo(ContactAttempt::max('contacted_at')));
    }

    public function test_filters_and_permissions(): void
    {
        $this->manual($this->emp, 'customer', 'busy');
        $other = $this->makeUser(Role::EMPLOYEE, ['name' => 'موظف ثاني']);
        $this->manual($other, 'courier', 'answered');

        $admin = $this->makeUser(Role::ADMIN);
        $res = $this->actingAs($admin)->get('/contacts?user_id='.$this->emp->id.'&party_type=customer&result=busy')->assertOk();
        $this->assertCount(1, $res->viewData('attempts')->items());
        $this->assertCount(0, $this->actingAs($admin)->get('/contacts?from='.today()->addDay()->toDateString())->viewData('attempts')->items());

        $this->actingAs($this->emp)->get('/contacts')->assertForbidden();
        $this->actingAs($this->makeUser(Role::VIEWER))->get('/contacts')->assertOk();
    }
}
