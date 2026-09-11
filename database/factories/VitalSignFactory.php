<?php

namespace Database\Factories;

use App\Models\ServiceRecord;
use App\Models\VitalSign;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<VitalSign> */
class VitalSignFactory extends Factory
{
    protected $model = VitalSign::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'service_record_id' => ServiceRecord::factory(),
            'measured_at' => now()->setTime(9, 45),
            'timing' => 'arrival',
            'temperature' => fake()->randomFloat(1, 35.8, 37.2),
            'systolic_bp' => fake()->numberBetween(105, 155),
            'diastolic_bp' => fake()->numberBetween(60, 90),
            'pulse' => fake()->numberBetween(58, 88),
            'spo2' => fake()->numberBetween(95, 99),
        ];
    }

    /** しきい値を外れた値。ルールベース判定のテストに使う。 */
    public function abnormal(): static
    {
        return $this->state(fn (): array => [
            'temperature' => 37.8,
            'systolic_bp' => 185,
            'spo2' => 92,
        ]);
    }
}
