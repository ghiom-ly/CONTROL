<?php

namespace Tests\Feature;

use App\Models\ContactAttempt;
use App\Models\DailyReport;
use App\Models\Issue;
use App\Models\Leave;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\StoreDailyMetric;
use App\Models\User;
use Tests\TestCase;

class AdminDeletionTest extends TestCase
{
    private User $emp;

    private User $boss;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->emp = $this->makeUser(Role::EMPLOYEE, ['name' => 'موظف تجريبي', 'username' => 'test.emp']);
        $this->boss = $this->makeUser(Role::SUPER_ADMIN);
        $this->store = Store::factory()->assignedTo($this->emp->id)->create();
    }

    /** تقرير معتمد بمتجر وكود */
    private function approvedReport(string $code = 'DEL1'): DailyReport
    {
        $this->actingAs($this->emp)->post('/reports/start', ['stores' => [['store_id' => $this->store->id, 'start_orders' => 10, 'start_rate' => 50]]]);
        $r = DailyReport::where('user_id', $this->emp->id)->latest('id')->first();
        $this->actingAs($this->emp)->put("/reports/{$r->id}", ['stores' => [['store_id' => $this->store->id, 'shipments_count' => 9,
            'codes' => [['code' => $code, 'result' => 'customer_no_answer']]]]])->assertSessionHasNoErrors();
        $this->actingAs($this->emp)->post("/reports/{$r->id}/submit", ['end_delivery_rate' => 60]);
        $this->actingAs($this->makeUser(Role::ADMIN))->post("/reports/{$r->id}/approve");

        return $r->fresh();
    }

    public function test_delete_report_removes_effects_and_restore_brings_them_back(): void
    {
        $r = $this->approvedReport();
        $this->assertSame(9, StoreDailyMetric::sole()->shipments_count);
        $this->assertSame(1, Shipment::count());

        $this->actingAs($this->boss)->delete("/reports/{$r->id}")->assertRedirect();
        $this->assertSoftDeleted($r);
        $this->assertSame(0, StoreDailyMetric::count());
        $this->assertSame(0, Shipment::count());
        $this->actingAs($this->boss)->get('/reports?trashed=1')->assertSee($r->report_number)->assertSee('استعادة');

        $this->actingAs($this->boss)->patch("/reports/{$r->id}/restore")->assertRedirect();
        $this->assertNotSoftDeleted($r);
        $this->assertSame(9, StoreDailyMetric::sole()->shipments_count);
        $this->assertSame(1, Shipment::sole()->followups_count);
    }

    public function test_only_super_admin_deletes_reports(): void
    {
        $r = $this->approvedReport();
        $this->actingAs($this->makeUser(Role::ADMIN))->delete("/reports/{$r->id}")->assertForbidden();
        $this->actingAs($this->emp)->delete("/reports/{$r->id}")->assertForbidden();
        $this->assertNotSoftDeleted($r);
    }

    public function test_purge_employee_removes_everything(): void
    {
        $r = $this->approvedReport();
        ContactAttempt::create(['party_type' => 'customer', 'user_id' => $this->emp->id, 'contacted_at' => now(), 'attempt_number' => 1, 'result' => 'answered']);
        Issue::create(['store_id' => $this->store->id, 'reported_by' => $this->emp->id, 'reported_at' => now(), 'type' => 'other', 'title' => 't', 'description' => 'd']);
        Leave::create(['user_id' => $this->emp->id, 'from_date' => today(), 'to_date' => today(), 'type' => 'leave']);

        $this->actingAs($this->boss)->delete("/users/{$this->emp->id}/purge", ['confirm' => 'wrong'])->assertSessionHasErrors('confirm');
        $this->assertNotNull(User::find($this->emp->id));

        $this->actingAs($this->boss)->delete("/users/{$this->emp->id}/purge", ['confirm' => 'test.emp'])->assertRedirect(route('users.index'));
        $this->assertNull(User::withTrashed()->find($this->emp->id));
        $this->assertSame(0, DailyReport::withTrashed()->count());
        $this->assertSame(0, ContactAttempt::count());
        $this->assertSame(0, Issue::withTrashed()->count());
        $this->assertSame(0, Leave::count());
        $this->assertSame(0, StoreDailyMetric::count());
        $this->assertNull($this->store->fresh()->assigned_user_id);
        $this->assertDatabaseHas('audit_logs', ['event' => 'user_purged']);
    }

    public function test_purge_works_for_soft_deleted_employee(): void
    {
        $this->emp->delete();
        $this->actingAs($this->boss)->delete("/users/{$this->emp->id}/purge", ['confirm' => 'test.emp'])->assertRedirect();
        $this->assertNull(User::withTrashed()->find($this->emp->id));
    }

    public function test_purge_guards(): void
    {
        $admin = $this->makeUser(Role::ADMIN, ['username' => 'adm']);
        $this->actingAs($admin)->delete("/users/{$this->emp->id}/purge", ['confirm' => 'test.emp'])->assertForbidden();
        $this->actingAs($this->boss)->delete("/users/{$admin->id}/purge", ['confirm' => 'adm'])->assertForbidden();   // ليس موظفاً
        $this->actingAs($this->boss)->delete("/users/{$this->boss->id}/purge", ['confirm' => $this->boss->username])->assertForbidden();
    }

    public function test_purge_button_on_user_page(): void
    {
        $this->actingAs($this->boss)->get("/users/{$this->emp->id}")->assertSee('حذف نهائي');
    }
}
