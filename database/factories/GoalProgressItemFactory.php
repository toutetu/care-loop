<?php

namespace Database\Factories;

use App\Enums\ProgressStatus;
use App\Models\CarePlanGoal;
use App\Models\GoalProgressItem;
use App\Models\GoalProgressReport;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GoalProgressItem> */
class GoalProgressItemFactory extends Factory
{
    protected $model = GoalProgressItem::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'goal_progress_report_id' => GoalProgressReport::factory(),
            'care_plan_goal_id' => CarePlanGoal::factory(),
            'progress_status' => ProgressStatus::Unchanged,
            'comment' => '期間を通じて一部介助での実施が継続しています。',
            'evidence' => [
                ['record_id' => 3301, 'date' => now()->subMonth()->toDateString(), 'excerpt' => '手すりを持ち、ほぼ自力で浴槽をまたがれた'],
            ],
        ];
    }

    /** 記録が足りず評価できない状態。無理な評価をさせないための選択肢。 */
    public function insufficientData(): static
    {
        return $this->state(fn (): array => [
            'progress_status' => ProgressStatus::InsufficientData,
            'comment' => '対象期間の記録が3件のみで、傾向を判断できる材料が不足しています。',
            'evidence' => [],
        ]);
    }
}
