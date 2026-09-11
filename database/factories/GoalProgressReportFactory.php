<?php

namespace Database\Factories;

use App\Models\CarePlan;
use App\Models\GoalProgressReport;
use App\Models\Resident;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GoalProgressReport> */
class GoalProgressReportFactory extends Factory
{
    protected $model = GoalProgressReport::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'resident_id' => Resident::factory(),
            'care_plan_id' => CarePlan::factory(),
            'period_from' => now()->subMonth()->startOfMonth()->toDateString(),
            'period_to' => now()->subMonth()->endOfMonth()->toDateString(),
            'overall_summary' => '入浴動作は概ね横ばいで、一部介助での実施が継続しています。一方、活動への参加時間が短くなる記録が複数見られます。',
            'next_actions' => [
                '水分摂取について、ご本人の好まれる飲み物をご家族に確認する',
                'レクリエーションの参加時間を記録項目に追加し、変化を定量的に追う',
            ],
            'confidence' => 'medium',
        ];
    }

    public function lowConfidence(): static
    {
        return $this->state(fn (): array => ['confidence' => 'low']);
    }
}
