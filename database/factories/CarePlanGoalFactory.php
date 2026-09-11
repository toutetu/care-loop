<?php

namespace Database\Factories;

use App\Models\CarePlan;
use App\Models\CarePlanGoal;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CarePlanGoal> */
class CarePlanGoalFactory extends Factory
{
    protected $model = CarePlanGoal::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'care_plan_id' => CarePlan::factory(),
            'goal_text' => fake()->randomElement([
                '手すりを使用して浴槽をまたぐ動作が、一部介助で行える。',
                '週2回の通所を継続し、他のご利用者との交流の機会を持つ。',
                '通所日に 1,200ml 以上の水分摂取ができる。',
                '個別機能訓練を週2回継続し、立ち上がり動作が安定する。',
            ]),
            'target_date' => now()->addMonths(3)->toDateString(),
            'sort_order' => fake()->numberBetween(0, 3),
        ];
    }
}
