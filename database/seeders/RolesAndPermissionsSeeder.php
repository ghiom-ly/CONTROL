<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Support\Permissions;
use Illuminate\Database\Seeder;

/** آمن لإعادة التشغيل: يضيف الصلاحيات الجديدة ولا يلغي تعديلات الإدارة على الأدوار الموجودة */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            Role::SUPER_ADMIN => ['المدير العام', 'جميع الصلاحيات وإدارة المستخدمين والإعدادات'],
            Role::ADMIN => ['المسؤول', 'مراجعة واعتماد التقارير وإدارة المشكلات ومتابعة الموظفين'],
            Role::EMPLOYEE => ['الموظف', 'إنشاء التقارير ورفع الملفات ومتابعة السجلات المسموح بها'],
            Role::VIEWER => ['المشاهد', 'عرض البيانات المصرح بها فقط'],
        ];

        $existingPermissionIds = Permission::pluck('id', 'name')->all();

        foreach (Permissions::all() as $name => [$label, $group]) {
            Permission::updateOrCreate(['name' => $name], ['display_name' => $label, 'group' => $group]);
        }
        $permissionIds = Permission::pluck('id', 'name');

        foreach ($roles as $name => [$display, $desc]) {
            $role = Role::firstOrNew(['name' => $name]);
            $isNew = ! $role->exists;
            $role->fill(['display_name' => $display, 'description' => $desc, 'is_system' => true])->save();

            $defaults = Permissions::defaults()[$name];
            if ($isNew) {
                $role->permissions()->sync($permissionIds->only($defaults)->values());
            } else {
                // أضف فقط الصلاحيات التي لم تكن موجودة سابقاً في النظام
                $newOnes = array_diff($defaults, array_keys($existingPermissionIds));
                $role->permissions()->syncWithoutDetaching($permissionIds->only($newOnes)->values());
            }
        }
    }
}
