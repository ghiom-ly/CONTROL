<?php

namespace Database\Factories;

use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'username' => 'u'.Str::lower(Str::random(10)),
            'email' => null,
            'phone' => '09'.fake()->numerify('########'),
            'password' => 'Password@123',
            'role_id' => fn () => Role::where('name', Role::EMPLOYEE)->value('id'),
            'status' => 'active',
            'hired_at' => now()->subYear(),
        ];
    }

    public function role(string $role): static
    {
        return $this->state(fn () => ['role_id' => Role::where('name', $role)->value('id')]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => 'inactive']);
    }
}
