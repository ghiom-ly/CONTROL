<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * الموظف: إدخال التقرير وإرساله فقط. تُزال صلاحيات شاشات المتابعة من دور الموظف
 * في قواعد البيانات الموجودة (البذر لا يحذف صلاحيات سابقة).
 */
return new class extends Migration
{
    private const REMOVED = ['stores.view', 'shipments.view', 'shipments.followup', 'contacts.view', 'contacts.create', 'issues.view'];

    public function up(): void
    {
        $role = DB::table('roles')->where('name', 'employee')->value('id');
        $ids = DB::table('permissions')->whereIn('name', self::REMOVED)->pluck('id');
        if ($role && $ids->isNotEmpty()) {
            DB::table('permission_role')->where('role_id', $role)->whereIn('permission_id', $ids)->delete();
        }
    }

    public function down(): void
    {
        $role = DB::table('roles')->where('name', 'employee')->value('id');
        if (! $role) {
            return;
        }
        foreach (DB::table('permissions')->whereIn('name', self::REMOVED)->pluck('id') as $pid) {
            DB::table('permission_role')->insertOrIgnore(['role_id' => $role, 'permission_id' => $pid]);
        }
    }
};
