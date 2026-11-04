<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class DailyReportFactory extends Factory
{
    public function definition(): array
    {
        return [
            'work_date' => today(),
            'user_id' => User::factory(),
            'status' => 'draft',
            'source' => 'manual',
            'shift_started_at' => now(),
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => 'draft']);
    }

    public function pendingReview(): static
    {
        return $this->state(fn () => ['status' => 'pending_review', 'submitted_at' => now()]);
    }

    public function approved(): static
    {
        return $this->state(fn () => ['status' => 'approved', 'submitted_at' => now(), 'approved_at' => now()]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => ['status' => 'rejected', 'submitted_at' => now(), 'rejection_reason' => 'سبب']);
    }
}
