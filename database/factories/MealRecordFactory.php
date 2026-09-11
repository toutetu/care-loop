<?php

namespace Database\Factories;

use App\Models\MealRecord;
use App\Models\ServiceRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MealRecord> */
class MealRecordFactory extends Factory
{
    protected $model = MealRecord::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'service_record_id' => ServiceRecord::factory(),
            'meal_type' => 'lunch',
            'staple_rate' => fake()->randomElement([50, 80, 100]),
            'side_rate' => fake()->randomElement([50, 80, 100]),
            'meal_form' => fake()->randomElement(['常食', '一口大', '刻み']),
            'choking' => false,
        ];
    }

    /** 摂取量が少ない日。低栄養リスク判定のテストに使う。 */
    public function lowIntake(): static
    {
        return $this->state(fn (): array => [
            'staple_rate' => 30,
            'side_rate' => 30,
        ]);
    }

    /** むせ込みがあった日。口頭連絡タスクの起点になる。 */
    public function withChoking(): static
    {
        return $this->state(fn (): array => ['choking' => true]);
    }
}
