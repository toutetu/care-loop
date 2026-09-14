<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Facility;
use App\Models\LlmJob;
use App\Models\MealRecord;
use App\Models\Resident;
use App\Models\ServiceRecord;
use App\Models\User;
use App\Models\VitalSign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * サービス提供記録の入力画面。
 *
 * この画面で確かめたいことは、入力できるかどうかではない。
 * AIが書いた文章と人が直した文章を区別できること、確定していない記録が
 * 確定済みに紛れないこと、未測定が0にならないことである。
 */
class ServiceRecordScreenTest extends TestCase
{
    use RefreshDatabase;

    private Facility $facility;

    private User $staff;

    private ServiceRecord $record;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facility = Facility::factory()->create();
        $this->staff = User::factory()->create([
            'facility_id' => $this->facility->id,
            'role' => UserRole::Staff,
        ]);

        $resident = Resident::factory()->for($this->facility)->create();

        $this->record = ServiceRecord::factory()->for($resident)->create([
            'recorded_by' => $this->staff->id,
            'service_date' => today(),
            'attendance_status' => 'attended',
            'confirmed_at' => null,
        ]);
    }

    // ---------------------------------------------------------------
    // 閲覧と編集の分離
    // ---------------------------------------------------------------

    public function test_記録した本人は編集できる(): void
    {
        $this->actingAs($this->staff)->get($this->editUrl())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('records/edit')
                ->where('canEdit', true)
            );
    }

    public function test_他の職員が記録した分も書き換えられる(): void
    {
        // 送迎・入浴・食事・帰宅で担当が入れ替わる。最初に触れた職員しか
        // 書けない作りでは、入浴を担当した職員が入浴の記録を残せない。
        $other = User::factory()->create([
            'facility_id' => $this->facility->id,
            'role' => UserRole::Staff,
        ]);

        $this->actingAs($other)->get($this->editUrl())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canEdit', true));

        $this->actingAs($other)
            ->put($this->updateUrl(), ['attendance_status' => 'attended'])
            ->assertRedirect();
    }

    public function test_管理者は他の職員の記録も編集できる(): void
    {
        $manager = User::factory()->create([
            'facility_id' => $this->facility->id,
            'role' => UserRole::Manager,
        ]);

        $this->actingAs($manager)->get($this->editUrl())
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canEdit', true));
    }

    public function test_別の事業所の職員は開けない(): void
    {
        $outsider = User::factory()->create([
            'facility_id' => Facility::factory()->create()->id,
        ]);

        $this->actingAs($outsider)->get($this->editUrl())->assertForbidden();
    }

    // ---------------------------------------------------------------
    // AIの出力と人の判断の区別
    // ---------------------------------------------------------------

    public function test_文章を書き換えたら人が直した印がつく(): void
    {
        $this->record->update(['record_text' => 'AIが生成した文章です。']);

        $this->actingAs($this->staff)->put($this->updateUrl(), [
            'attendance_status' => 'attended',
            'record_text' => 'AIが生成した文章です。職員が補足しました。',
        ]);

        $this->record->refresh();

        $this->assertTrue($this->record->record_text_edited_by_human);
    }

    public function test_文章が変わっていなければ印はつかない(): void
    {
        // 画面を開いて保存しただけでは、職員はまだ内容を判断していない。
        // 実際に内容が変わったかどうかで判定する。
        $this->record->update(['record_text' => 'AIが生成した文章です。']);

        $this->actingAs($this->staff)->put($this->updateUrl(), [
            'attendance_status' => 'attended',
            'record_text' => 'AIが生成した文章です。',
        ]);

        $this->record->refresh();

        $this->assertFalse($this->record->record_text_edited_by_human);
    }

    // ---------------------------------------------------------------
    // 確定
    // ---------------------------------------------------------------

    public function test_保存しただけでは確定しない(): void
    {
        // 記録は1日かけて少しずつ埋まる。保存＝確定にすると、
        // 途中まで入力して保存した記録が確定済みになってしまう。
        $this->actingAs($this->staff)->put($this->updateUrl(), [
            'attendance_status' => 'attended',
            'record_text' => '午前中は体操に参加された。',
        ]);

        $this->assertNull($this->record->refresh()->confirmed_at);
    }

    public function test_確定にチェックを入れると確定する(): void
    {
        // ブラウザのチェックボックスは真偽値ではなく文字列 "1" を送る。
        // ここを true で書いていたため、厳密比較の誤りを見逃していた。
        $this->actingAs($this->staff)->put($this->updateUrl(), [
            'attendance_status' => 'attended',
            'confirm' => '1',
        ]);

        $this->assertNotNull($this->record->refresh()->confirmed_at);
    }

    public function test_チェックを外したままなら確定しない(): void
    {
        // 外したチェックボックスは項目そのものが送られてこない
        $this->actingAs($this->staff)->put($this->updateUrl(), [
            'attendance_status' => 'attended',
        ]);

        $this->assertNull($this->record->refresh()->confirmed_at);
    }

    public function test_確定済みの記録は保存しても確定時刻が変わらない(): void
    {
        // 保存のたびに確定時刻が動くと、いつ確認したのかが分からなくなる
        $confirmedAt = now()->subDay()->startOfMinute();
        $this->record->forceFill(['confirmed_at' => $confirmedAt])->save();

        $this->actingAs($this->staff)->put($this->updateUrl(), [
            'attendance_status' => 'attended',
            'confirm' => '1',
        ]);

        $this->assertSame(
            $confirmedAt->toDateTimeString(),
            $this->record->refresh()->confirmed_at->toDateTimeString(),
        );
    }

    public function test_確定済みの記録もai下書きなら未確認として画面に渡る(): void
    {
        // AIが文章を書き直すと確定は外れる（TransformVoiceNote）。
        // 画面がこの状態を確定済みとして描くと、職員が読んでいない下書きが
        // そのまま法定文書になってしまう。
        // どのAI実行で生成したかが分からない文章は、AI下書きとは扱わない。
        // 職員が自分で書いた文章まで未確認として警告すると、警告が意味を失う。
        $this->record->forceFill([
            'record_text' => 'AIが生成した文章です。',
            'llm_job_id' => LlmJob::factory()->create()->id,
            'confirmed_at' => null,
            'record_text_edited_by_human' => false,
        ])->save();

        $this->actingAs($this->staff)->get($this->editUrl())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('record.confirmedAt', null)
                ->where('record.hasAiDraft', true)
            );
    }

    // ---------------------------------------------------------------
    // バイタル・食事
    // ---------------------------------------------------------------

    public function test_未測定の項目は0ではなく空で保存される(): void
    {
        // 0で埋めると「測って0だった」と読めてしまい、記録として誤りになる。
        // リスク判定のしきい値にも引っかかる。
        VitalSign::factory()->for($this->record)->create([
            'temperature' => 36.5,
            'systolic_bp' => 120,
        ]);

        $this->actingAs($this->staff)->put($this->updateUrl(), [
            'attendance_status' => 'attended',
            'vital' => ['temperature' => 36.8, 'systolic_bp' => null],
        ]);

        $vital = $this->record->refresh()->vitalSigns()->first();

        $this->assertSame('36.8', (string) $vital->temperature);
        $this->assertNull($vital->systolic_bp);
    }

    public function test_バイタルが未登録でも入力すれば作られる(): void
    {
        $this->actingAs($this->staff)->put($this->updateUrl(), [
            'attendance_status' => 'attended',
            'vital' => ['temperature' => 37.1],
        ]);

        $this->assertSame(1, $this->record->refresh()->vitalSigns()->count());
    }

    public function test_昼食の記録は同じ行を更新する(): void
    {
        // service_record_id と meal_type に一意制約がある。
        // 保存のたびに行が増えると制約違反になる。
        MealRecord::factory()->for($this->record)->create([
            'meal_type' => 'lunch',
            'staple_rate' => 50,
        ]);

        $this->actingAs($this->staff)->put($this->updateUrl(), [
            'attendance_status' => 'attended',
            'lunch' => ['staple_rate' => 80, 'side_rate' => 100, 'meal_form' => '常食'],
        ]);

        $meals = $this->record->refresh()->mealRecords;

        $this->assertCount(1, $meals);
        $this->assertSame(80, $meals->first()->staple_rate);
    }

    // ---------------------------------------------------------------
    // 入力値の検証
    // ---------------------------------------------------------------

    public function test_あり得ない体温は保存できない(): void
    {
        // 桁の打ち間違いをその場で気づけるようにする。
        // 保存してしまうと、しきい値判定が誤った警告を出す。
        $this->actingAs($this->staff)
            ->from($this->editUrl())
            ->put($this->updateUrl(), [
                'attendance_status' => 'attended',
                'vital' => ['temperature' => 365],
            ])
            ->assertSessionHasErrors('vital.temperature');
    }

    public function test_帰宅時刻が到着時刻より前なら保存できない(): void
    {
        $this->actingAs($this->staff)
            ->from($this->editUrl())
            ->put($this->updateUrl(), [
                'attendance_status' => 'attended',
                'arrival_time' => '16:00',
                'departure_time' => '09:30',
            ])
            ->assertSessionHasErrors('departure_time');
    }

    public function test_原文は保存できるが記録として確定はしない(): void
    {
        // raw_note は音声入力の未加工のテキスト。AIが何を変えたのかを
        // 後から検証できるよう、そのまま残す。
        $this->actingAs($this->staff)->put($this->updateUrl(), [
            'attendance_status' => 'attended',
            'raw_note' => 'えーっと 午前中は体操に参加されて',
        ]);

        $this->record->refresh();

        $this->assertSame('えーっと 午前中は体操に参加されて', $this->record->raw_note);
        $this->assertNull($this->record->confirmed_at);
    }

    // ---------------------------------------------------------------

    private function editUrl(): string
    {
        return route('records.edit', $this->record);
    }

    private function updateUrl(): string
    {
        return route('records.update', $this->record);
    }
}
