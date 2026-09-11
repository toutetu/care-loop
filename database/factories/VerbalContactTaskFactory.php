<?php

namespace Database\Factories;

use App\Enums\VerbalContactStatus;
use App\Models\Resident;
use App\Models\VerbalContactTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<VerbalContactTask> */
class VerbalContactTaskFactory extends Factory
{
    protected $model = VerbalContactTask::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'resident_id' => Resident::factory(),
            'topic' => fake()->randomElement(['食事中のむせ込み', '体重の減少', '水分摂取量の低下']),
            'reason' => '頻度や状況によって意味が変わる事項であり、ご家族が質問できる形でお伝えする必要があるため。',
            'urgency' => 'same_day',
            'source' => 'llm',
            'status' => VerbalContactStatus::Pending,
        ];
    }

    /** 未連絡のまま日をまたいだタスク。管理者への通知対象。 */
    public function overdue(): static
    {
        return $this->state(fn (): array => [
            'status' => VerbalContactStatus::Pending,
            'created_at' => now()->subDays(2),
        ]);
    }
}
