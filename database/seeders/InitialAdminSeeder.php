<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class InitialAdminSeeder extends Seeder
{
    public function run(): void
    {
        $role = Role::where('name', Role::SUPER_ADMIN)->firstOrFail();
        if (User::where('role_id', $role->id)->exists()) {
            return;
        }

        $cfg = config('nours.initial_admin');
        $password = $cfg['password'] ?: Str::password(14, symbols: false);

        User::create([
            'name' => $cfg['name'],
            'username' => $cfg['username'],
            'password' => $password,
            'role_id' => $role->id,
            'status' => 'active',
            'must_change_password' => true,
        ]);

        if ($this->command) {
            $this->command->warn("تم إنشاء المدير العام: {$cfg['username']}");
            if (! $cfg['password']) {
                $this->command->warn("كلمة المرور المؤقتة: {$password}  (سيُطلب تغييرها عند أول دخول)");
            }
        }
    }
}
