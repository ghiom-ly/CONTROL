<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** المرحلة 6: رابط الشحنة الخارجي، صلاحية ملخص الكود، اتصالات الموظف، ومهلة المشكلات القائمة */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        DB::table('system_settings')->insertOrIgnore([
            'key' => 'shipment_url_template', 'value' => 'https://backoffice.nawris.algoriza.com/orders/{code}', 'type' => 'string',
            'group' => 'reports', 'label' => 'رابط صفحة الشحنة في منظومة الشحن', 'description' => '{code} مكان الكود — اتركه فارغاً لإلغاء الروابط',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        DB::table('permissions')->insertOrIgnore(['name' => 'shipments.lookup', 'display_name' => 'عرض ملخص الكود',
            'group' => 'الشحنات', 'created_at' => $now, 'updated_at' => $now]);
        $employee = DB::table('roles')->where('name', 'employee')->value('id');
        if ($employee) {
            foreach (DB::table('permissions')->whereIn('name', ['shipments.lookup', 'contacts.create'])->pluck('id') as $pid) {
                DB::table('permission_role')->insertOrIgnore(['role_id' => $employee, 'permission_id' => $pid]);
            }
        }

        $sla = (int) (DB::table('system_settings')->where('key', 'issue_resolution_sla_hours')->value('value') ?: 48);
        DB::table('issues')->whereNull('due_at')->update(['due_at' => DB::raw("DATE_ADD(reported_at, INTERVAL {$sla} HOUR)")]);

        Cache::forget('system_settings.all');
    }

    public function down(): void
    {
        DB::table('system_settings')->where('key', 'shipment_url_template')->delete();
        DB::table('permissions')->where('name', 'shipments.lookup')->delete();
    }
};
