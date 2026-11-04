<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateRolePermissionsRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class RoleController extends Controller
{
    public function index(): View
    {
        $this->authorize('settings.manage');

        return view('settings.roles', [
            'roles' => Role::with('permissions:id,name')->withCount('users')->orderBy('id')->get(),
            'permissions' => Permission::orderBy('id')->get()->groupBy('group'),
        ]);
    }

    public function update(UpdateRolePermissionsRequest $request): RedirectResponse
    {
        $matrix = $request->validated('permissions') ?? [];
        $permissionIds = Permission::pluck('id', 'name');

        DB::transaction(function () use ($matrix, $permissionIds) {
            foreach (Role::with('permissions:id,name')->get() as $role) {
                if ($role->name === Role::SUPER_ADMIN) {
                    continue; // المدير العام يملك جميع الصلاحيات دائماً
                }
                $before = $role->permissions->pluck('name')->sort()->values()->all();
                $after = collect($matrix[$role->name] ?? [])->unique()->sort()->values()->all();
                // صلاحية الإعدادات محصورة بالمدير العام
                $after = array_values(array_diff($after, ['settings.manage']));
                if ($before !== $after) {
                    $role->permissions()->sync($permissionIds->only($after)->values());
                    AuditLogger::log('permissions_updated', "تعديل صلاحيات دور {$role->display_name}", $role,
                        ['permissions' => $before], ['permissions' => $after]);
                }
            }
        });

        return back()->with('success', 'تم حفظ مصفوفة الصلاحيات.');
    }
}
