<?php

namespace Tests\Feature;

use App\Models\ContactAttempt;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\Store;
use Tests\TestCase;

class ContactLogTest extends TestCase
{
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = Store::factory()->create();
        Shipment::create(['code' => '4725962', 'store_id' => $this->store->id, 'registered_at' => today(), 'processing_status' => 'open']);
    }

    public function test_employee_logs_customer_contact(): void
    {
        $emp = $this->makeUser();
        $this->actingAs($emp)->post('/shipments/4725962/contacts', ['party_type' => 'customer', 'result' => 'no_answer', 'notes' => 'x'])
            ->assertRedirect(route('shipments.show', '4725962'));
        $c = ContactAttempt::sole();
        $this->assertSame(1, $c->attempt_number);
        $this->assertSame($this->store->id, $c->store_id);
        $this->assertSame('4725962', $c->shipment_code);
        $this->assertSame($emp->id, $c->user_id);

        $this->actingAs($emp)->post('/shipments/4725962/contacts', ['party_type' => 'customer', 'result' => 'answered']);
        $this->assertSame(2, ContactAttempt::latest('id')->first()->attempt_number);
        $this->actingAs($emp)->post('/shipments/4725962/contacts', ['party_type' => 'courier', 'result' => 'busy']);
        $this->assertSame(1, ContactAttempt::latest('id')->first()->attempt_number);
    }

    public function test_employee_cannot_log_store_contact(): void
    {
        $emp = $this->makeUser();
        $this->actingAs($emp)->post('/shipments/4725962/contacts', ['party_type' => 'store', 'result' => 'answered'])
            ->assertSessionHasErrors('party_type');
        $this->actingAs($emp)->post("/stores/{$this->store->id}/contacts", ['result' => 'answered'])->assertForbidden();
        $this->assertSame(0, ContactAttempt::count());
    }

    public function test_admin_logs_store_contact(): void
    {
        $this->actingAs($this->makeUser(Role::ADMIN))->post("/stores/{$this->store->id}/contacts", ['result' => 'answered', 'notes' => 'تم الاتفاق'])
            ->assertRedirect();
        $c = ContactAttempt::sole();
        $this->assertSame('store', $c->party_type->value);
        $this->assertSame($this->store->id, $c->store_id);
        $this->assertNull($c->shipment_code);
        $this->actingAs($this->makeUser(Role::ADMIN))->get("/stores/{$this->store->id}")->assertSee('تم الاتفاق');
    }

    public function test_cannot_log_for_unknown_code(): void
    {
        $this->actingAs($this->makeUser())->post('/shipments/NOPE9/contacts', ['party_type' => 'customer', 'result' => 'answered'])->assertNotFound();
    }

    public function test_employee_sees_only_own_contacts_on_code_page(): void
    {
        $other = $this->makeUser(Role::EMPLOYEE, ['name' => 'زميل آخر']);
        $this->actingAs($other)->post('/shipments/4725962/contacts', ['party_type' => 'customer', 'result' => 'no_answer', 'notes' => 'ملاحظة الزميل']);

        $this->actingAs($this->makeUser())->get('/shipments/4725962')->assertOk()
            ->assertDontSee('ملاحظة الزميل')->assertSee('تسجيل اتصال');
        $this->actingAs($this->makeUser(Role::ADMIN))->get('/shipments/4725962')->assertSee('ملاحظة الزميل')->assertSee('زميل آخر');
    }
}
