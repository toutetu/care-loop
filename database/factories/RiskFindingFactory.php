<?php

namespace Database\Factories;

use App\Enums\RiskCategory;
use App\Enums\RiskSeverity;
use App\Enums\RiskSource;
use App\Models\RiskAssessment;
use App\Models\RiskFinding;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RiskFinding> */
class RiskFindingFactory extends Factory
{
    protected $model = RiskFinding::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'risk_assessment_id' => RiskAssessment::factory(),
            'category' => RiskCategory::Malnutrition,
            'severity' => RiskSeverity::High,
            'source' => RiskSource::RuleBased,
            'title' => '1ヶ月で体重が 3.5% 減少',
            'reason' => '前月 42.6kg から当月 41.1kg へ減少（減少率 3.5%）。3%以上は低栄養リスクの判定基準に該当する。',
            'evidence' => [
                ['record_id' => 2891, 'date' => now()->startOfMonth()->toDateString(), 'excerpt' => '体重記録 41.1kg'],
            ],
            'suggested_actions' => [
                'ご家族に、ご自宅での食事量・間食の状況を確認する',
                '昼食の摂取割合を1週間、毎回記録して推移を確認する',
            ],
        ];
    }

    /** AIが自由記述から拾った指摘。要確認として扱う。 */
    public function llmDetected(): static
    {
        return $this->state(fn (): array => [
            'source' => RiskSource::LlmDetected,
            'category' => RiskCategory::Fall,
            'severity' => RiskSeverity::Medium,
            'title' => '「ふらつき」に関する記述が3週間で4件に増加',
            'reason' => '自由記述に「ふらつき」「足の上がりが浅い」といった記述が増えている。バイタルには異常値が出ていない。',
        ]);
    }
}
