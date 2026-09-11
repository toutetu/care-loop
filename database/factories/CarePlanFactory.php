<?php

namespace Database\Factories;

use App\Models\CarePlan;
use App\Models\Resident;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CarePlan> */
class CarePlanFactory extends Factory
{
    protected $model = CarePlan::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $from = now()->subMonths(5)->startOfMonth();

        return [
            'resident_id' => Resident::factory(),
            'period_from' => $from,
            'period_to' => $from->copy()->addMonths(6)->endOfMonth(),
            'long_term_goal' => fake()->randomElement([
                'ご自宅の浴室で、手すりを使って安全に入浴動作が行えるようになる。',
                'ご自宅内をつたい歩きで安全に移動できるようになる。',
                '他のご利用者との交流を通じて、外出への意欲を取り戻す。',
            ]),
            'status' => 'active',
        ];
    }
}
