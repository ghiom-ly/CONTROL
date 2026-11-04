<?php

namespace Tests\Feature;

use App\Models\Role;
use Tests\TestCase;

/** معيار القبول 14: لا يستطيع الموظف الوصول إلى صلاحيات المسؤول */
class AuthorizationTest extends TestCase
{
    public function test_employee_cannot_access_admin_areas(): void
    {
        $employee = $this->makeUser(Role::EMPLOYEE);
        $other = $this->makeUser(Role::EMPLOYEE);
        $this->actingAs($employee);

        $this->get('/users')->assertForbidden();
        $this->get("/users/{$other->id}")->assertForbidden();
        $this->get('/settings')->assertForbidden();
        $this->get('/settings/roles')->assertForbidden();
        $this->get('/audit-logs')->assertForbidden();
        $this->post('/users', ['name' => 'x'])->assertForbidden();
        $this->put("/users/{$other->id}", ['name' => 'hacked'])->assertForbidden();
        $this->patch("/users/{$other->id}/toggle-status")->assertForbidden();
        $this->post("/users/{$other->id}/reset-password")->assertForbidden();
        $this->delete("/users/{$other->id}")->assertForbidden();
        $this->put('/settings/roles', ['permissions' => ['employee' => ['users.manage']]])->assertForbidden();
    }

    public function test_employee_can_view_own_profile_only(): void
    {
        $employee = $this->makeUser(Role::EMPLOYEE);
        $this->actingAs($employee)->get("/users/{$employee->id}")->assertOk();
    }

    public function test_admin_can_view_but_not_manage_users(): void
    {
        $admin = $this->makeUser(Role::ADMIN);
        $employee = $this->makeUser(Role::EMPLOYEE);
        $this->actingAs($admin);

        $this->get('/users')->assertOk();
        $this->get("/users/{$employee->id}")->assertOk();
        $this->get('/audit-logs')->assertOk();
        $this->get('/settings')->assertForbidden();
        $this->patch("/users/{$employee->id}/toggle-status")->assertForbidden();
    }

    public function test_viewer_is_read_only(): void
    {
        $viewer = $this->makeUser(Role::VIEWER);
        $this->actingAs($viewer)->get('/')->assertOk();
        $this->get('/users')->assertForbidden();
        $this->post('/users', [])->assertForbidden();
    }

    public function test_super_admin_has_everything(): void
    {
        $this->actingAs($this->makeUser(Role::SUPER_ADMIN));
        foreach (['/', '/users', '/settings', '/settings/roles', '/audit-logs', '/search?q=ab', '/modules/stores'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_sidebar_hides_unauthorized_links(): void
    {
        $this->actingAs($this->makeUser(Role::EMPLOYEE))->get('/')
            ->assertOk()->assertDontSee(route('settings.index'))->assertDontSee(route('audit.index'));
    }
}
