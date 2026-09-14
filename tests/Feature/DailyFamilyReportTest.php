<?php

namespace Tests\Feature;

use App\Enums\BathingType;
use App\Enums\VerbalContactStatus;
use App\Models\Facility;
use App\Models\MealRecord;
use App\Models\Resident;
use App\Models\ServiceRecord;
use App\Models\User;
use App\Models\VerbalContactTask;
use App\Models\VitalSign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 日次の連絡帳（F-16）。送迎時にご家族へお渡しする1枚。
 *
 * 月次のまとめだけでは、ご家族はその日の様子を知る手段がない。
 * 体調の変化に気づいたときにすぐ共有できないと、気づきが翌月まで届かない。
 */
class DailyFamilyReportTest extends TestCase
{
    use RefreshDatabase;

    private Facility $facility;

    private User $staff;

    private ServiceRecord $record;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facility = Facility::factory()->create(['name' => 'さくら苑デイサービス']);
        $this->staff = User::factory()->create([
            'facility_id' => $this->facility->id,
            'name' => '山口 みどり',
        ]);

        $resident = Resident::factory()->for($this->facility)->create([
            'name' => '佐藤 ハナ',
            'name_kana' => 'サトウ ハナ',
        ]);

        $this->record = ServiceRecord::factory()->for($resident)->create([
            'recorded_by' => $this->staff->id,
            'service_date' => '2026-09-11',
            'arrival_time' => '09:30',
            'departure_time' => '16:15',
            'total_water_ml' => 1250,
            'family_text' => '本日もお元気にお過ごしでした。塗り絵では色を丁寧に塗っておられました。',
        ]);
    }

    // ---------------------------------------------------------------
    // 出力内容
    // ---------------------------------------------------------------

    public function test_連絡帳にその日の様子と記録が載る(): void
    {
        VitalSign::factory()->for($this->record)->create([
            'temperature' => 36.4,
            'systolic_bp' => 128,
            'diastolic_bp' => 74,
            'pulse' => 68,
        ]);

        MealRecord::factory()->for($this->record)->create([
            'meal_type' => 'lunch',
            'staple_rate' => 80,
            'side_rate' => 100,
            'meal_form' => '常食',
        ]);

        $response = $this->actingAs($this->staff)->get($this->url());

        $response->assertOk();
        $response->assertSee('ご利用連絡帳');
        $response->assertSee('さくら苑デイサービス');
        $response->assertSee('佐藤 ハナ 様');
        $response->assertSee('2026年9月11日（金）');
        $response->assertSee('塗り絵では色を丁寧に塗っておられました。');
        $response->assertSee('36.4 ℃');
        $response->assertSee('128 / 74 mmHg');
        $response->assertSee('記入者：山口 みどり');
    }

    public function test_水分摂取量は連絡帳に載せない(): void
    {
        // 記録としては保持し脱水リスクの判定にも使うが、提供のたびに正確な量を
        // 把握するのは現場では難しい。不確かな数値はかえって誤解を生む。
        $response = $this->actingAs($this->staff)->get($this->url());

        $response->assertDontSee('1,250 ml');
        $response->assertDontSee('お水・お茶');
    }

    public function test_入浴の有無が載る(): void
    {
        $this->record->update(['bathing_type' => BathingType::Bath]);

        $this->actingAs($this->staff)->get($this->url())
            ->assertSee('入浴')
            ->assertSee('お入りになりました');
    }

    public function test_清拭は入浴とは別の表現で載る(): void
    {
        // 入浴を見送った日も清拭は行う。「お休みされました」と書くと、
        // 何もしなかったようにご家族へ伝わる。
        $this->record->update(['bathing_type' => BathingType::Wipe]);

        $this->actingAs($this->staff)->get($this->url())
            ->assertSee('体を拭いてさっぱりしていただきました')
            ->assertDontSee('本日はお休みされました');
    }

    public function test_入浴の記録がなければ実施なしとは書かない(): void
    {
        // 記録がないことと、実施しなかったことは違う
        $this->record->update(['bathing_type' => null]);

        $this->actingAs($this->staff)->get($this->url())
            ->assertDontSee('本日はお休みされました')
            ->assertDontSee('お入りになりました');
    }

    public function test_事業所からのお知らせが載る(): void
    {
        $this->facility->update([
            'notice' => "朝晩の冷え込みが増えてまいりました。\n【今月の行事】18日（木）敬老会",
        ]);

        $this->actingAs($this->staff)->get($this->url())
            ->assertSee('事業所からのお知らせ')
            ->assertSee('18日（木）敬老会');
    }

    public function test_お知らせが未設定なら欄ごと出さない(): void
    {
        $this->facility->update(['notice' => null]);

        $this->actingAs($this->staff)->get($this->url())
            ->assertDontSee('事業所からのお知らせ');
    }

    public function test_次回のご利用予定は利用曜日から算出される(): void
    {
        // 次回予定を別項目として管理しない。二重に持つと必ずどちらかが古くなる
        $this->record->resident->update(['service_weekdays' => [1, 3, 5]]);

        // 2026-09-11 は金曜。次の利用日は 9/14（月）
        $this->actingAs($this->staff)->get($this->url())
            ->assertSee('次回のご利用予定')
            ->assertSee('9月14日（月）');
    }

    public function test_切り取り線とご家族の記入欄がある(): void
    {
        $response = $this->actingAs($this->staff)->get($this->url());

        $response->assertSee('きりとり線');
        $response->assertSee('ご家族からの連絡欄');
        $response->assertSee('次回ご利用時に職員にお渡しください');
        // 切り離された紙片だけでも、どなたのものか分かるようにする
        $response->assertSee('2026年9月11日 分');
    }

    public function test_摂取割合はご家族が読める表現になる(): void
    {
        // 「80」ではなく「8割ほど」。ご家族が読む文書に内部表現を出さない
        MealRecord::factory()->for($this->record)->create([
            'meal_type' => 'lunch',
            'staple_rate' => 80,
            'side_rate' => 50,
        ]);

        $response = $this->actingAs($this->staff)->get($this->url());

        $response->assertSee('8割ほど');
        $response->assertSee('半分ほど');
    }

    public function test_未測定の項目はゼロではなく未測定と表示する(): void
    {
        VitalSign::factory()->for($this->record)->create([
            'temperature' => null,
            'systolic_bp' => null,
            'pulse' => null,
        ]);

        $response = $this->actingAs($this->staff)->get($this->url());

        $response->assertSee('未測定');
        $response->assertDontSee('0 ℃');
    }

    public function test_aiが動かなくてもバイタルは印刷できる(): void
    {
        // LLMの障害でご家族への連絡が止まってはいけない（要件定義 8章 可用性）
        $this->record->update(['family_text' => null]);

        VitalSign::factory()->for($this->record)->create(['temperature' => 36.4]);

        $response = $this->actingAs($this->staff)->get($this->url());

        $response->assertOk();
        $response->assertSee('36.4 ℃');
        $response->assertSee('担当職員より口頭でお伝えいたします');
    }

    // ---------------------------------------------------------------
    // 口頭連絡との連動
    // ---------------------------------------------------------------

    public function test_口頭連絡が必要な事項は連絡帳にも載る(): void
    {
        // 「文書に書いたうえで、口頭でも伝える」という方針
        VerbalContactTask::factory()->create([
            'resident_id' => $this->record->resident_id,
            'service_record_id' => $this->record->id,
            'topic' => '食事中のむせ込み',
            'status' => VerbalContactStatus::Pending,
        ]);

        $response = $this->actingAs($this->staff)->get($this->url());

        $response->assertSee('お迎えの際に職員よりお伝えします');
        $response->assertSee('食事中のむせ込み');
    }

    public function test_連絡済みの事項は連絡帳に載らない(): void
    {
        VerbalContactTask::factory()->create([
            'resident_id' => $this->record->resident_id,
            'service_record_id' => $this->record->id,
            'topic' => '先週お伝えした件',
            'status' => VerbalContactStatus::Completed,
        ]);

        $response = $this->actingAs($this->staff)->get($this->url());

        $response->assertDontSee('先週お伝えした件');
    }

    // ---------------------------------------------------------------
    // 権限
    // ---------------------------------------------------------------

    public function test_別の事業所の職員は閲覧できない(): void
    {
        // 介護記録は要配慮個人情報。所属していない事業所の記録を見る理由がない
        $other = User::factory()->create([
            'facility_id' => Facility::factory()->create()->id,
        ]);

        $this->actingAs($other)->get($this->url())->assertForbidden();
    }

    public function test_未ログインでは閲覧できない(): void
    {
        $this->get($this->url())->assertRedirect();
    }

    // ---------------------------------------------------------------

    private function url(): string
    {
        return route('records.family-report', $this->record);
    }
}
