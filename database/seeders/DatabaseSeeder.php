<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * 投入順は依存関係のとおり。
 *   1. 要介護度マスタ（制度上決まっている7区分）
 *   2. デモデータ（架空の事業所・職員・ご利用者と、約3ヶ月分の記録）
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CareLevelSeeder::class,
            DemoDataSeeder::class,
        ]);
    }
}
