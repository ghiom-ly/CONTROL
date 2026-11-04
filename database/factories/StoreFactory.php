<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class StoreFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'متجر '.fake()->unique()->numerify('####'),
            'phone' => '09'.fake()->numerify('########'),
            'status' => 'active',
        ];
    }

    public function assignedTo(int $userId): static
    {
        return $this->state(fn () => ['assigned_user_id' => $userId]);
    }
}
