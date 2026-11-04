<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * بيانات الإنتاج الأساسية فقط (أدوار، صلاحيات، إعدادات، قواعد تنبيهات، المدير العام).
 * البيانات التجريبية منفصلة تماماً: php artisan db:seed --class=DemoSeeder
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            SystemSettingsSeeder::class,
            AlertRulesSeeder::class,
            InitialAdminSeeder::class,
        ]);
    }
}
