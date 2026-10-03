<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Facility;
use App\Models\Resident;
use App\Models\ServiceRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * デモの1日ぶんを進めるコマンド。
 *
 * 確かめたいのは「毎朝どういう状態が用意されるか」である。
 * 当日ぶんはAIを動かす前で止まっていること、前日までは片づいていること、
 * そして本物のデータベースでは動かないこと。
 */
class GenerateDemoDayTest extends TestCase
{
    use RefreshDatabase;

    private Facility $facility;

    private Resident $resident;

    protected function setUp(): void
    {
        parent::setUp();

        config(['careloop.is_demo' => true]);

        $this->facility = Facility::factory()->create();

        User::factory()->create([
            'facility_id' => $this->facility->id,
            'role' => UserRole::Staff,
            'is_active' => true,
        ]);

        // 今日が利用日の方。曜日は実行日に合わせる。
        $this->resident = Resident::factory()->for($this->facility)->create([
            'service_weekdays' => [today()->dayOfWeekIso],
        ]);
    }

    // ---------------------------------------------------------------
    // 動かしてよい場面かどうか
    // ---------------------------------------------------------------

    public function test_デモ環境でなければ動かない(): void
    {
        // 本物の事業所のデータベースに架空の記録が混ざってはいけない
        config(['careloop.is_demo' => false]);

        $this->artisan('careloop:demo-day')->assertFailed();

        $this->assertSame(0, ServiceRecord::query()->count());
    }

    public function test_記録者がいなければ動かない(): void
    {
        User::query()->delete();

        $this->artisan('careloop:demo-day')->assertFailed();

        $this->assertSame(0, ServiceRecord::query()->count());
    }

    // ---------------------------------------------------------------
    // 当日ぶん
    // ---------------------------------------------------------------

    public function test_当日ぶんは未確定で文章を作る前の状態にする(): void
    {
        /*
         * 見に来た人に触ってもらう余地を残すのが目的である。
         * 原文だけが入っていて、3つの文体はまだ無い状態を作る。
         */
        $this->artisan('careloop:demo-day')->assertSuccessful();

        $record = ServiceRecord::query()->sole();

        $this->assertTrue($record->service_date->isToday());
        $this->assertNull($record->confirmed_at, '確定していない記録として置く');
        $this->assertNull($record->record_text, 'AI変換の前なので記録用の文章は無い');
        $this->assertNull($record->family_text);
        $this->assertNull($record->handover_note);
        $this->assertSame(1, $record->notes()->count(), '原文は入っている');
    }

    public function test_当日ぶんは入浴と食事を空けておく(): void
    {
        // 入浴入力・食事入力の表を、実際に埋めてもらうための余地
        $this->artisan('careloop:demo-day');

        $record = ServiceRecord::query()->sole();

        $this->assertSame(0, $record->bathingRecords()->count());
        $this->assertSame(0, $record->mealRecords()->count());
        $this->assertSame(1, $record->vitalSigns()->count(), '到着時のバイタルは測り終えている');
    }

    public function test_利用曜日でない方の記録は作らない(): void
    {
        Resident::factory()->for($this->facility)->create([
            'service_weekdays' => [today()->addDay()->dayOfWeekIso],
        ]);

        $this->artisan('careloop:demo-day');

        $this->assertSame(1, ServiceRecord::query()->count());
    }

    public function test_すでにある記録は作り直さない(): void
    {
        // 手で足した記録や、見に来た人が書いた記録を上書きしない
        $existing = ServiceRecord::factory()->for($this->resident)->create([
            'service_date' => today(),
            'record_text' => '手で入れた記録',
        ]);

        $this->artisan('careloop:demo-day');

        $this->assertSame(1, ServiceRecord::query()->count());
        $this->assertSame('手で入れた記録', $existing->fresh()?->record_text);
    }

    // ---------------------------------------------------------------
    // 前日まで
    // ---------------------------------------------------------------

    public function test_前日までの未確定は書き終えた状態にする(): void
    {
        /*
         * 未確定のまま積み上がると、ダッシュボードの「未確定の記録」が
         * 増え続け、見せたい数字が読めなくなる。
         */
        $yesterday = ServiceRecord::factory()->for($this->resident)->create([
            'service_date' => today()->subDay(),
            'record_text' => null,
            'family_text' => null,
            'handover_note' => null,
            'confirmed_at' => null,
        ]);

        $this->artisan('careloop:demo-day');

        $settled = $yesterday->fresh();

        $this->assertNotNull($settled?->confirmed_at);
        $this->assertNotNull($settled?->record_text);
        $this->assertNotNull($settled?->family_text);
        $this->assertNotNull($settled?->handover_note);
        $this->assertSame(1, $settled?->bathingRecords()->count());
        $this->assertSame(1, $settled?->mealRecords()->count());
    }

    public function test_前日までに人が書いた文章は残す(): void
    {
        $yesterday = ServiceRecord::factory()->for($this->resident)->create([
            'service_date' => today()->subDay(),
            'record_text' => '職員が書いた記録',
            'confirmed_at' => null,
        ]);

        $this->artisan('careloop:demo-day');

        $this->assertSame('職員が書いた記録', $yesterday->fresh()?->record_text);
    }

    public function test_確定済みの記録には触らない(): void
    {
        $confirmed = ServiceRecord::factory()->for($this->resident)->create([
            'service_date' => today()->subDay(),
            'confirmed_at' => today()->subDay()->setTime(17, 0),
        ]);

        $before = $confirmed->bathingRecords()->count();

        $this->artisan('careloop:demo-day');

        $this->assertSame($before, $confirmed->fresh()?->bathingRecords()->count());
    }

    public function test_二度動かしても同じ結果になる(): void
    {
        // 予定が重なって走ることがある。同じ日ぶんが二重に積まれてはいけない。
        $this->artisan('careloop:demo-day');
        $this->artisan('careloop:demo-day');

        $this->assertSame(1, ServiceRecord::query()->whereDate('service_date', today())->count());
        $this->assertSame(1, ServiceRecord::query()->sole()->notes()->count());
    }
}
