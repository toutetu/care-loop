<?php

namespace Database\Factories;

use App\Models\IncidentReport;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<IncidentReport> */
class IncidentReportFactory extends Factory
{
    protected $model = IncidentReport::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'resident_id' => Resident::factory(),
            'reported_by' => User::factory(),
            'category' => fake()->randomElement(['fall', 'choking', 'skin', 'other']),
            'severity' => 'near_miss',
            'occurred_at' => fake()->dateTimeBetween('-3 months', 'now'),
            'description' => fake()->randomElement([
                '送迎車の乗降時、ステップでふらつきが見られた。職員2名で介助し転倒には至らず。',
                '歩行器使用中、方向転換の際にバランスを崩されたが、職員が支えて転倒を防いだ。',
                '食事中に一度むせ込みが見られた。水分でむせたもので、その後は問題なく摂取された。',
            ]),
            'response' => '状況を確認し、ご本人に体調の変化がないことを確認した。',
            'prevention' => '同様の場面では職員2名での介助を徹底する。',
            'family_notified' => false,
        ];
    }

    public function fall(): static
    {
        return $this->state(fn (): array => ['category' => 'fall']);
    }
}
