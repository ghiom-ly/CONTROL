<?php

namespace Database\Factories;

use App\Models\DailyReport;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

class ReportStoreFactory extends Factory
{
    public function definition(): array
    {
        return [
            'daily_report_id' => DailyReport::factory(),
            'store_id' => Store::factory(),
            'shipments_count' => fake()->numberBetween(1, 50),
        ];
    }
}
