<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** المرحلة 8: شعار الشركة، وصلاحية استخراج التقارير للمشاهد */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        DB::table('system_settings')->insertOrIgnore([
            'key' => 'company_logo', 'value' => null, 'type' => 'string', 'group' => 'general',
            'label' => 'شعار الشركة', 'description' => 'يظهر في رأس التقارير المستخرجة', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $viewer = DB::table('roles')->where('name', 'viewer')->value('id');
        $perm = DB::table('permissions')->where('name', 'exports.generate')->value('id');
        if ($viewer && $perm) {
            DB::table('permission_role')->insertOrIgnore(['role_id' => $viewer, 'permission_id' => $perm]);
        }
        Cache::forget('system_settings.all');
    }

    public function down(): void
    {
        DB::table('system_settings')->where('key', 'company_logo')->delete();
        $viewer = DB::table('roles')->where('name', 'viewer')->value('id');
        $perm = DB::table('permissions')->where('name', 'exports.generate')->value('id');
        if ($viewer && $perm) {
            DB::table('permission_role')->where(['role_id' => $viewer, 'permission_id' => $perm])->delete();
        }
        Cache::forget('system_settings.all');
    }
};
