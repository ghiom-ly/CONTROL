<?php

namespace Tests;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SystemSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, SystemSettingsSeeder::class]);
    }

    protected function makeUser(string $role = Role::EMPLOYEE, array $attrs = []): User
    {
        return User::factory()->role($role)->create($attrs);
    }
}
