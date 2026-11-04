<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Tests\TestCase;

class StoreAssignmentTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->makeUser(Role::ADMIN);
    }

    public function test_lists_employees_with_store_counts_and_unassigned(): void
    {
        $ahmed = $this->makeUser(Role::EMPLOYEE, ['name' => 'أحمد التجريبي']);
        Store::factory()->assignedTo($ahmed->id)->create(['name' => 'متجر أ']);
        Store::factory()->assignedTo($ahmed->id)->create(['name' => 'متجر ب']);
        Store::factory()->create(['name' => 'متجر حر']);
        $this->makeUser(Role::ADMIN, ['name' => 'مسؤول آخر مختلف']);

        $this->actingAs($this->admin)->get('/stores/assignment')->assertOk()
            ->assertSee('أحمد التجريبي')->assertSee('(2)', false)->assertSee('غير مُسندة')->assertSee('متجر حر')
            ->assertDontSee('مسؤول آخر مختلف');
    }

    public function test_bulk_assign_and_unassign(): void
    {
        $emp = $this->makeUser();
        $s1 = Store::factory()->create();
        $s2 = Store::factory()->create();

        $this->actingAs($this->admin)->post('/stores/assignment', ['store_ids' => [$s1->id, $s2->id], 'user_id' => $emp->id])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame([$emp->id, $emp->id], [$s1->fresh()->assigned_user_id, $s2->fresh()->assigned_user_id]);
        $this->assertDatabaseHas('audit_logs', ['auditable_type' => Store::class, 'auditable_id' => $s1->id, 'event' => 'updated']);

        $this->actingAs($this->admin)->post('/stores/assignment', ['store_ids' => [$s1->id], 'user_id' => 'none']);
        $this->assertNull($s1->fresh()->assigned_user_id);
    }

    public function test_cannot_assign_to_admin_or_inactive(): void
    {
        $s = Store::factory()->create();
        $inactive = $this->makeUser(Role::EMPLOYEE, ['status' => 'inactive']);
        foreach ([$this->admin->id, $inactive->id] as $bad) {
            $this->actingAs($this->admin)->post('/stores/assignment', ['store_ids' => [$s->id], 'user_id' => $bad])->assertSessionHasErrors('user_id');
        }
        $this->assertNull($s->fresh()->assigned_user_id);
    }

    public function test_archived_not_listed_or_assigned(): void
    {
        $s = Store::factory()->create(['name' => 'متجر مؤرشف جداً', 'status' => 'archived']);
        $this->actingAs($this->admin)->get('/stores/assignment')->assertDontSee('متجر مؤرشف جداً');
        $this->actingAs($this->admin)->post('/stores/assignment', ['store_ids' => [$s->id], 'user_id' => $this->makeUser()->id])
            ->assertSessionHasErrors('store_ids.0');
    }

    public function test_employee_forbidden(): void
    {
        $this->actingAs($this->makeUser())->get('/stores/assignment')->assertForbidden();
    }
}
