<?php

namespace Database\Factories;

use App\Enums\LlmFeature;
use App\Enums\LlmJobStatus;
use App\Models\LlmJob;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LlmJob> */
class LlmJobFactory extends Factory
{
    protected $model = LlmJob::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'feature' => LlmFeature::VoiceTransform,
            'status' => LlmJobStatus::Queued,
            'attempts' => 0,
        ];
    }

    public function succeeded(): static
    {
        return $this->state(fn (): array => [
            'status' => LlmJobStatus::Succeeded,
            'attempts' => 1,
            'started_at' => now()->subSeconds(12),
            'finished_at' => now(),
            'result' => ['record_text' => '整形済みの記録テキスト。'],
        ]);
    }

    /** JSONスキーマ不一致で中止した状態。HTTP 200 で返る失敗の再現。 */
    public function failedWithSchemaMismatch(): static
    {
        return $this->state(fn (): array => [
            'status' => LlmJobStatus::Failed,
            'attempts' => 2,
            'error_type' => 'schema_mismatch',
            'error_message' => '必須項目 evidence が欠落しています。',
            'started_at' => now()->subSeconds(20),
            'finished_at' => now(),
        ]);
    }
}
