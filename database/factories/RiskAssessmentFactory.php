<?php

namespace Database\Factories;

use App\Models\Resident;
use App\Models\RiskAssessment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RiskAssessment> */
class RiskAssessmentFactory extends Factory
{
    protected $model = RiskAssessment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'resident_id' => Resident::factory(),
            'period_from' => now()->subMonths(3)->toDateString(),
            'period_to' => now()->toDateString(),
            'assessed_at' => now(),
            'no_risk_detected' => false,
            'confidence' => 'medium',
        ];
    }

    public function reviewed(): static
    {
        return $this->state(fn (): array => ['reviewed_at' => now()]);
    }
}
