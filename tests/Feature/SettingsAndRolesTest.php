<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use Tests\TestCase;

class SettingsAndRolesTest extends TestCase
{
    public function test_permission_matrix_update_takes_effect_and_is_audited(): void
    {
        $boss = $this->makeUser(Role::SUPER_ADMIN);
        $viewer = $this->makeUser(Role::VIEWER);
        $this->actingAs($viewer)->get('/users')->assertForbidden();

        $this->actingAs($boss)->put('/settings/roles', [
            'permissions' => [
                'viewer' => ['dashboard.view', 'users.view', 'settings.manage'],
                'admin' => \App\Support\Permissions::defaults()['admin'],
                'employee' => \App\Support\Permissions::defaults()['employee'],
            ],
        ])->assertRedirect();

        $this->assertDatabaseHas('audit_logs', ['event' => 'permissions_updated']);
        $fresh = User::find($viewer->id);
        $this->assertTrue($fresh->hasPermission('users.view'));
        $this->assertFalse($fresh->hasPermission('settings.manage'), 'settings.manage محصورة بالمدير العام');
        $this->actingAs($fresh)->get('/users')->assertOk();
    }

    public function test_rejects_unknown_permission(): void
    {
        $this->actingAs($this->makeUser(Role::SUPER_ADMIN))
            ->put('/settings/roles', ['permissions' => ['viewer' => ['hack.all']]])
            ->assertSessionHasErrors();
    }

    public function test_settings_update(): void
    {
        $this->actingAs($this->makeUser(Role::SUPER_ADMIN))->put('/settings', ['settings' => [
            'company_name' => 'شركة النورس للشحن', 'report_submission_deadline' => '17:30',
            'report_review_sla_hours' => 12, 'store_inactive_days' => 5, 'store_decline_percent' => 25,
            'issue_resolution_sla_hours' => 48, 'import_max_rows' => 5000, 'employee_can_edit_rejected' => '0',
        ]])->assertSessionHasNoErrors();

        $this->assertSame('شركة النورس للشحن', SystemSetting::get('company_name'));
        $this->assertSame(5, SystemSetting::get('store_inactive_days'));
        $this->assertFalse(SystemSetting::get('employee_can_edit_rejected'));
    }

    public function test_store_cannot_be_considered_stopped_after_one_day(): void
    {
        $this->actingAs($this->makeUser(Role::SUPER_ADMIN))->put('/settings', ['settings' => [
            'company_name' => 'x', 'report_submission_deadline' => '18:00', 'report_review_sla_hours' => 24,
            'store_inactive_days' => 1, 'store_decline_percent' => 30, 'issue_resolution_sla_hours' => 48, 'import_max_rows' => 5000,
        ]])->assertSessionHasErrors('settings.store_inactive_days');
    }
}
