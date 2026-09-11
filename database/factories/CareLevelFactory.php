<?php

namespace Database\Factories;

use App\Models\CareLevel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * 要介護度マスタ。実運用では Seeder で7区分を固定投入する。
 * このファクトリはテストで1件だけ必要なときに使う。
 *
 * @extends Factory<CareLevel>
 */
class CareLevelFactory extends Factory
{
    protected $model = CareLevel::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $levels = [
            ['support1', '要支援1', 1],
            ['support2', '要支援2', 2],
            ['care1', '要介護1', 3],
            ['care2', '要介護2', 4],
            ['care3', '要介護3', 5],
            ['care4', '要介護4', 6],
            ['care5', '要介護5', 7],
        ];

        [$code, $name, $sortOrder] = fake()->randomElement($levels);

        return [
            'code' => $code,
            'name' => $name,
            'sort_order' => $sortOrder,
        ];
    }
}
