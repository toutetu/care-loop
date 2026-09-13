<?php

namespace Database\Seeders;

use App\Models\CareLevel;
use Illuminate\Database\Seeder;

/**
 * 要介護度マスタ。介護保険制度で定められた7区分。
 *
 * ファクトリではなくシーダーで投入する。これは架空のデータではなく
 * 制度上決まっている値であり、ランダムに生成してよいものではないため。
 */
class CareLevelSeeder extends Seeder
{
    public function run(): void
    {
        $levels = [
            ['code' => 'support1', 'name' => '要支援1', 'sort_order' => 1],
            ['code' => 'support2', 'name' => '要支援2', 'sort_order' => 2],
            ['code' => 'care1', 'name' => '要介護1', 'sort_order' => 3],
            ['code' => 'care2', 'name' => '要介護2', 'sort_order' => 4],
            ['code' => 'care3', 'name' => '要介護3', 'sort_order' => 5],
            ['code' => 'care4', 'name' => '要介護4', 'sort_order' => 6],
            ['code' => 'care5', 'name' => '要介護5', 'sort_order' => 7],
        ];

        foreach ($levels as $level) {
            CareLevel::query()->updateOrCreate(['code' => $level['code']], $level);
        }
    }
}
