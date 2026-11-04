<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    private function payload(array $override = []): array
    {
        return array_merge([
            'name' => 'سالم عمر', 'username' => 'Salem.Omar', 'phone' => '٠٩٢١١١٢٢٣٣',
            'role_id' => Role::where('name', Role::EMPLOYEE)->value('id'), 'status' => 'active',
            'password' => 'Salem1234', 'password_confirmation' => 'Salem1234',
        ], $override);
    }

    public function test_super_admin_creates_employee(): void
    {
        $boss = $this->makeUser(Role::SUPER_ADMIN);
        $this->actingAs($boss)->post('/users', $this->payload())->assertRedirect();

        $user = User::where('username', 'salem.omar')->firstOrFail();
        $this->assertSame('0921112233', $user->phone);        // تحويل الأرقام العربية
        $this->assertTrue($user->must_change_password);
        $this->assertSame($boss->id, $user->created_by);
        $this->assertDatabaseHas('audit_logs', ['event' => 'created', 'auditable_type' => User::class, 'auditable_id' => $user->id]);
    }

    public function test_validation_rejects_bad_input(): void
    {
        $this->actingAs($this->makeUser(Role::SUPER_ADMIN));
        $this->makeUser(Role::EMPLOYEE, ['username' => 'taken']);

        $this->post('/users', $this->payload(['username' => 'taken']))->assertSessionHasErrors('username');
        $this->post('/users', $this->payload(['username' => 'مستخدم']))->assertSessionHasErrors('username');
        $this->post('/users', $this->payload(['password' => 'short', 'password_confirmation' => 'short']))->assertSessionHasErrors('password');
        $this->post('/users', $this->payload(['role_id' => 9999]))->assertSessionHasErrors('role_id');
    }

    public function test_only_super_admin_can_grant_super_admin_role(): void
    {
        // نمنح المسؤول صلاحية الإدارة مؤقتاً لاختبار حماية الدور
        $admin = $this->makeUser(Role::ADMIN);
        $admin->role->permissions()->attach(\App\Models\Permission::where('name', 'users.manage')->value('id'));

        $this->actingAs($admin)->post('/users', $this->payload([
            'role_id' => Role::where('name', Role::SUPER_ADMIN)->value('id'),
        ]))->assertSessionHasErrors('role_id');
        $this->assertDatabaseMissing('users', ['username' => 'salem.omar']);
    }

    public function test_admin_with_manage_permission_cannot_edit_super_admin(): void
    {
        $admin = $this->makeUser(Role::ADMIN);
        $admin->role->permissions()->attach(\App\Models\Permission::where('name', 'users.manage')->value('id'));
        $boss = $this->makeUser(Role::SUPER_ADMIN);

        $this->actingAs($admin)->patch("/users/{$boss->id}/toggle-status")->assertForbidden();
    }

    public function test_cannot_disable_last_super_admin_or_self(): void
    {
        $boss = $this->makeUser(Role::SUPER_ADMIN);
        $this->actingAs($boss)->patch("/users/{$boss->id}/toggle-status")->assertSessionHasErrors();
        $this->assertTrue($boss->fresh()->isActive());

        $this->delete("/users/{$boss->id}")->assertSessionHasErrors();
        $this->assertNull($boss->fresh()->deleted_at);
    }

    public function test_toggle_status_and_soft_delete_preserve_records(): void
    {
        $boss = $this->makeUser(Role::SUPER_ADMIN);
        $emp = $this->makeUser();
        $this->actingAs($boss);

        $this->patch("/users/{$emp->id}/toggle-status")->assertSessionHasNoErrors();
        $this->assertSame('inactive', $emp->fresh()->status->value);

        $this->delete("/users/{$emp->id}")->assertRedirect(route('users.index'));
        $this->assertSoftDeleted('users', ['id' => $emp->id]);

        $this->patch("/users/{$emp->id}/restore")->assertRedirect();
        $this->assertNull($emp->fresh()->deleted_at);
    }

    public function test_reset_password_creates_temporary_password(): void
    {
        $boss = $this->makeUser(Role::SUPER_ADMIN);
        $emp = $this->makeUser(Role::EMPLOYEE, ['password' => 'Old12345']);

        $response = $this->actingAs($boss)->post("/users/{$emp->id}/reset-password");
        $temp = $response->getSession()->get('temp_password');

        $this->assertNotEmpty($temp);
        $emp->refresh();
        $this->assertTrue($emp->must_change_password);
        $this->assertTrue(password_verify($temp, $emp->password));
        $this->assertDatabaseHas('audit_logs', ['event' => 'password_reset', 'auditable_id' => $emp->id]);
    }

    public function test_users_list_filters_work(): void
    {
        $this->actingAs($this->makeUser(Role::SUPER_ADMIN, ['name' => 'المدير']));
        $this->makeUser(Role::EMPLOYEE, ['name' => 'أحمد سالم']);
        $this->makeUser(Role::EMPLOYEE, ['name' => 'فاطمة علي', 'status' => 'inactive']);

        $this->get('/users?q=أحمد')->assertSee('أحمد سالم')->assertDontSee('فاطمة علي');
        $this->get('/users?status=inactive')->assertSee('فاطمة علي')->assertDontSee('أحمد سالم');
    }
}
