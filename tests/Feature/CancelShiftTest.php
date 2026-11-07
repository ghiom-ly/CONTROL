<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\DailyReport;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Tests\TestCase;

class CancelShiftTest extends TestCase
{
    private User $emp;

    private User $admin;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->emp = $this->makeUser(Role::EMPLOYEE);
        $this->admin = $this->makeUser(Role::ADMIN);
        $this->store = Store::factory()->assignedTo($this->emp->id)->create();
    }

    private function startShift(): DailyReport
    {
        $this->actingAs($this->emp)->post('/reports/start', ['stores' => [['store_id' => $this->store->id, 'start_orders' => 10, 'start_rate' => 50]]])
            ->assertSessionHasNoErrors();

        return DailyReport::where('user_id', $this->emp->id)->latest('id')->firstOrFail();
    }

    public function test_admin_cancels_mistaken_shift_and_employee_can_start_again(): void
    {
        $r = $this->startShift();
        $this->actingAs($this->admin)->get("/reports/{$r->id}")->assertOk()->assertSee('إلغاء الشفت');

        $this->actingAs($this->admin)->delete("/reports/{$r->id}/cancel-shift", ['reason' => 'ضغط بالغلط'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSoftDeleted($r);
        $this->assertTrue(AuditLog::where('event', 'shift_cancelled')->where('user_id', $this->admin->id)->exists());

        $this->startShift();
        $this->assertSame(1, DailyReport::where('user_id', $this->emp->id)->count());
    }

    public function test_cannot_cancel_submitted_report(): void
    {
        $r = $this->startShift();
        $r->update(['status' => 'pending_review', 'submitted_at' => now(), 'first_submitted_at' => now()]);
        $this->actingAs($this->admin)->delete("/reports/{$r->id}/cancel-shift")->assertSessionHasErrors('shift');
        $this->assertNotSoftDeleted($r);
        $this->actingAs($this->admin)->get("/reports/{$r->id}")->assertDontSee('إلغاء الشفت');
    }

    public function test_employee_and_reviewer_without_permission_cannot_cancel(): void
    {
        $r = $this->startShift();
        $this->actingAs($this->emp)->delete("/reports/{$r->id}/cancel-shift")->assertForbidden();
        $other = $this->makeUser(Role::EMPLOYEE);
        $this->actingAs($other)->delete("/reports/{$r->id}/cancel-shift")->assertForbidden();
        $this->assertNotSoftDeleted($r);
    }

    public function test_super_admin_can_cancel_too(): void
    {
        $r = $this->startShift();
        $this->actingAs($this->makeUser(Role::SUPER_ADMIN))->delete("/reports/{$r->id}/cancel-shift")->assertRedirect();
        $this->assertSoftDeleted($r);
    }
}
