<?php

namespace Database\Factories;

use App\Models\Facility;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Facility> */
class FacilityFactory extends Factory
{
    protected $model = Facility::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['さくら苑', 'ひだまり', 'あおぞら', 'なごみ']).'デイサービス',
            'service_type' => 'day_service',
            'capacity' => fake()->randomElement([15, 20, 25]),
        ];
    }
}
