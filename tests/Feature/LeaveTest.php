<?php

namespace Tests\Feature;

use App\Models\Leave;
use App\Models\Role;
use Tests\TestCase;

class LeaveTest extends TestCase
{
    public function test_admin_records_and_deletes_leave(): void
    {
        $emp = $this->makeUser();
        $admin = $this->makeUser(Role::ADMIN);
        $this->actingAs($admin)->post('/attendance/leaves', ['user_id' => $emp->id, 'from_date' => '2026-11-05', 'to_date' => '2026-11-07', 'type' => 'mission', 'notes' => 'زيارة'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $l = Leave::sole();
        $this->assertSame($admin->id, $l->created_by);
        $this->assertSame('mission', $l->type);

        $this->actingAs($admin)->delete("/attendance/leaves/{$l->id}")->assertRedirect();
        $this->assertSame(0, Leave::count());
    }

    public function test_leave_validation(): void
    {
        $admin = $this->makeUser(Role::ADMIN);
        $this->actingAs($admin)->post('/attendance/leaves', ['user_id' => $this->makeUser()->id, 'from_date' => '2026-11-07', 'to_date' => '2026-11-05', 'type' => 'leave'])
            ->assertSessionHasErrors('to_date');
        $this->actingAs($admin)->post('/attendance/leaves', ['user_id' => $admin->id, 'from_date' => '2026-11-05', 'to_date' => '2026-11-05', 'type' => 'leave'])
            ->assertSessionHasErrors('user_id');
        $this->actingAs($admin)->post('/attendance/leaves', ['user_id' => $this->makeUser()->id, 'from_date' => '2026-11-05', 'to_date' => '2026-11-05', 'type' => 'holiday'])
            ->assertSessionHasErrors('type');
    }

    public function test_employee_forbidden(): void
    {
        $emp = $this->makeUser();
        $this->actingAs($emp)->post('/attendance/leaves', ['user_id' => $emp->id, 'from_date' => '2026-11-05', 'to_date' => '2026-11-05', 'type' => 'leave'])
            ->assertForbidden();
    }
}
