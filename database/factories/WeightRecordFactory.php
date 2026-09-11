<?php

namespace Database\Factories;

use App\Models\Resident;
use App\Models\WeightRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WeightRecord> */
class WeightRecordFactory extends Factory
{
    protected $model = WeightRecord::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'resident_id' => Resident::factory(),
            'measured_on' => now()->startOfMonth()->toDateString(),
            'weight_kg' => fake()->randomFloat(1, 38.0, 62.0),
        ];
    }
}
