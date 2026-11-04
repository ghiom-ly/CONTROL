<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * بيانات تجريبية للعرض والتدريب فقط — لا تُشغَّل على قاعدة بيانات الإنتاج.
 * php artisan db:seed --class=DemoSeeder
 * (تُوسَّع في المراحل القادمة بمتاجر وتقارير تجريبية)
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('لا يمكن تشغيل البيانات التجريبية في بيئة الإنتاج.');

            return;
        }

        $roles = Role::pluck('id', 'name');
        $users = [
            ['مسؤول تجريبي', 'demo.admin', Role::ADMIN],
            ['أحمد سالم', 'demo.ahmed', Role::EMPLOYEE],
            ['فاطمة علي', 'demo.fatima', Role::EMPLOYEE],
            ['محمد خليفة', 'demo.mohamed', Role::EMPLOYEE],
            ['مشاهد تجريبي', 'demo.viewer', Role::VIEWER],
        ];
        foreach ($users as [$name, $username, $role]) {
            User::firstOrCreate(['username' => $username], [
                'name' => $name,
                'password' => 'Demo@12345',
                'role_id' => $roles[$role],
                'status' => 'active',
                'phone' => '09'.random_int(10000000, 99999999),
                'hired_at' => now()->subMonths(random_int(1, 24))->toDateString(),
            ]);
        }
        $this->command?->info('مستخدمو العرض: demo.admin / demo.ahmed ... كلمة المرور: Demo@12345');
    }
}
