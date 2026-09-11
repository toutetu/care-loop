<?php

namespace Tests\Feature;

use App\Models\CareLevel;
use App\Models\Facility;
use App\Models\Resident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 利用者情報のデータ保護設計が実際に機能していることを検証する。
 *
 * 設計書に「暗号化します」と書くだけでは意味がない。データベースに何が
 * 保存されているかを生のクエリで確認し、平文が残っていないことを
 * テストとして固定する。
 */
class ResidentDataProtectionTest extends TestCase
{
    use RefreshDatabase;

    private function makeResident(array $attributes = []): Resident
    {
        $facility = Facility::create([
            'name' => 'さくら苑デイサービス',
            'capacity' => 20,
        ]);

        $careLevel = CareLevel::create([
            'code' => 'care2',
            'name' => '要介護2',
            'sort_order' => 4,
        ]);

        return Resident::create(array_merge([
            'facility_id' => $facility->id,
            'care_level_id' => $careLevel->id,
            'name' => '佐藤 ハナ',
            'name_kana' => 'サトウ ハナ',
            'insurance_number' => '0123456789',
            'phone' => '090-1234-5678',
            'address' => '大阪府大阪市西区土佐堀2-2-4',
            'birth_date' => '1938-04-12',
        ], $attributes));
    }

    public function test_個人情報はデータベース上に平文で保存されない(): void
    {
        $resident = $this->makeResident();

        $raw = DB::table('residents')->where('id', $resident->id)->first();

        $this->assertStringNotContainsString('佐藤', $raw->name);
        $this->assertStringNotContainsString('サトウ', $raw->name_kana);
        $this->assertStringNotContainsString('0123456789', $raw->insurance_number);
        $this->assertStringNotContainsString('090-1234-5678', $raw->phone);
        $this->assertStringNotContainsString('土佐堀', $raw->address);
    }

    public function test_モデル経由では復号されて読める(): void
    {
        $resident = $this->makeResident();

        $fresh = $resident->fresh();

        $this->assertSame('佐藤 ハナ', $fresh->name);
        $this->assertSame('0123456789', $fresh->insurance_number);
    }

    public function test_生年月日は平文で保存される(): void
    {
        // 年齢での絞り込み・並べ替えに使うため、あえて暗号化しない（要件定義 9.3.2節）
        $resident = $this->makeResident();

        $raw = DB::table('residents')->where('id', $resident->id)->first();

        $this->assertStringContainsString('1938-04-12', (string) $raw->birth_date);
    }

    public function test_blind_indexで氏名カナを検索できる(): void
    {
        $resident = $this->makeResident();

        $found = Resident::whereKana('サトウ ハナ')->first();

        $this->assertNotNull($found);
        $this->assertSame($resident->id, $found->id);
    }

    public function test_表記ゆれがあっても同じ利用者が見つかる(): void
    {
        // 現場では入力者によって全角・半角・ひらがなが混ざる。
        // 正規化しないと同じ人を検索できない。
        $resident = $this->makeResident();

        $variations = ['さとう はな', 'ｻﾄｳ ﾊﾅ', 'サトウハナ', 'サトウ・ハナ', 'サトウ　ハナ'];

        foreach ($variations as $variation) {
            $found = Resident::whereKana($variation)->first();

            $this->assertNotNull($found, "「{$variation}」で検索できませんでした");
            $this->assertSame($resident->id, $found->id);
        }
    }

    public function test_別人のカナでは見つからない(): void
    {
        $this->makeResident();

        $this->assertNull(Resident::whereKana('タナカ ヨシコ')->first());
    }

    public function test_索引は保存のたびに更新される(): void
    {
        $resident = $this->makeResident();
        $originalHash = $resident->name_kana_hash;

        $resident->update(['name_kana' => 'タナカ ヨシコ']);

        $this->assertNotSame($originalHash, $resident->fresh()->name_kana_hash);
        $this->assertNotNull(Resident::whereKana('タナカ ヨシコ')->first());
        $this->assertNull(Resident::whereKana('サトウ ハナ')->first());
    }

    public function test_年齢は生年月日から算出される(): void
    {
        $resident = $this->makeResident([
            'birth_date' => now()->subYears(88)->subDay()->toDateString(),
        ]);

        $this->assertSame(88, $resident->age);
    }

    public function test_生年月日が未登録なら年齢はnullになる(): void
    {
        // 不明なものを0歳と表示しない
        $resident = $this->makeResident(['birth_date' => null]);

        $this->assertNull($resident->age);
    }
}
