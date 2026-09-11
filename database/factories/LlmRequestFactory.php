<?php

namespace Database\Factories;

use App\Enums\LlmFeature;
use App\Models\LlmJob;
use App\Models\LlmRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LlmRequest> */
class LlmRequestFactory extends Factory
{
    protected $model = LlmRequest::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $model = (string) config('llm.default_model');
        $inputTokens = fake()->numberBetween(3000, 5000);
        $outputTokens = fake()->numberBetween(800, 1800);

        return [
            'llm_job_id' => LlmJob::factory(),
            'feature' => LlmFeature::VoiceTransform,
            'model' => $model,
            'prompt_version' => config('llm.prompt_version'),
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'cache_read_input_tokens' => (int) ($inputTokens * 0.7),
            'latency_ms' => fake()->numberBetween(4000, 15000),
            'status' => 'success',
            'retry_count' => 0,
            'stop_reason' => 'end_turn',
            'estimated_cost_usd' => LlmRequest::calculateCostUsd($model, $inputTokens, $outputTokens),
        ];
    }

    public function failed(string $errorType = 'rate_limit_error'): static
    {
        return $this->state(fn (): array => [
            'status' => 'failed',
            'error_type' => $errorType,
            'output_tokens' => 0,
            'estimated_cost_usd' => 0,
        ]);
    }
}
