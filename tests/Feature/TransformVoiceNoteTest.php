<?php

namespace Tests\Feature;

use App\Llm\Clients\FakeClient;
use App\Llm\LlmGateway;
use App\Llm\LlmRequestLogger;
use App\Llm\Prompts\VoiceTransformPrompt;
use App\Llm\Support\ResponseValidator;
use App\Llm\UseCases\TransformVoiceNote;
use App\Models\Facility;
use App\Models\Resident;
use App\Models\ServiceRecord;
use App\Models\User;
use App\Models\VerbalContactTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * F-LLM-05 音声入力の三面変換。
 *
 * 特に重視しているのは、外部へ送信する本文に実名が含まれないことの検証である。
 * 「マスキングしています」と設計書に書くだけでは意味がない。
 * 実際に送られた文字列を取り出して、氏名が残っていないことを確かめる。
 */
class TransformVoiceNoteTest extends TestCase
{
    use RefreshDatabase;

    private FakeClient $fake;

    private TransformVoiceNote $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fake = new FakeClient;
        $this->useCase = new TransformVoiceNote(
            new LlmGateway($this->fake, new ResponseValidator, new LlmRequestLogger),
            new VoiceTransformPrompt,
        );
    }

    // ---------------------------------------------------------------
    // 個人情報の保護
    // ---------------------------------------------------------------

    public function test_送信本文にご利用者の実名が含まれない(): void
    {
        $record = $this->makeRecord(
            residentName: '佐藤 ハナ',
            rawNote: 'えーっと佐藤ハナさん今日は入浴のとき浴槽またぐの右足あがり悪くて腰支えた',
        );

        $this->useCase->handle($record);

        $sent = (string) $this->fake->lastRequest()?->userMessage;

        $this->assertStringNotContainsString('佐藤', $sent);
        $this->assertStringNotContainsString('ハナ', $sent);
        $this->assertStringContainsString('{{RESIDENT_1}}', $sent);
    }

    public function test_自由記述に書かれた他のご利用者の実名もマスクされる(): void
    {
        // プロンプトで「他のご利用者に触れない」と指示していても、
        // 送信前に置換しておけば、指示が守られなくても実名は漏れない
        $facility = Facility::factory()->create();
        $other = Resident::factory()->for($facility)->create(['name' => '田中 ヨシ子', 'name_kana' => 'タナカ ヨシコ']);

        $record = $this->makeRecord(
            residentName: '佐藤 ハナ',
            rawNote: '昼食のとき田中ヨシ子さんと話が弾んでいた',
            facility: $facility,
        );

        $this->useCase->handle($record);

        $sent = (string) $this->fake->lastRequest()?->userMessage;

        $this->assertStringNotContainsString('田中', $sent);
        $this->assertStringNotContainsString('ヨシ子', $sent);
        $this->assertNotNull($other->id);
    }

    public function test_職員の氏名もマスクされる(): void
    {
        $record = $this->makeRecord(
            residentName: '佐藤 ハナ',
            rawNote: '山口が付き添って歩行訓練を実施',
            staffName: '山口 みどり',
        );

        $this->useCase->handle($record);

        $sent = (string) $this->fake->lastRequest()?->userMessage;

        $this->assertStringNotContainsString('山口', $sent);
    }

    public function test_生年月日ではなく年齢を送る(): void
    {
        $record = $this->makeRecord(residentName: '佐藤 ハナ', rawNote: '特変なし');
        $record->resident->update(['birth_date' => now()->subYears(88)->subDay()->toDateString()]);

        $this->useCase->handle($record);

        $sent = (string) $this->fake->lastRequest()?->userMessage;

        $this->assertStringContainsString('88歳', $sent);
        $this->assertStringNotContainsString($record->resident->birth_date->format('Y-m-d'), $sent);
    }

    public function test_応答のプレースホルダは実名へ復元される(): void
    {
        $record = $this->makeRecord(residentName: '佐藤 ハナ', rawNote: '入浴介助を実施');

        $this->fake->queueParsed($this->response([
            'family_text' => '{{RESIDENT_1}} 様は本日もお元気にお過ごしでした。',
        ]));

        $result = $this->useCase->handle($record);

        $this->assertStringContainsString('佐藤 ハナ', (string) $result['family_text']);
        $this->assertStringNotContainsString('{{RESIDENT_1}}', (string) $result['family_text']);
    }

    // ---------------------------------------------------------------
    // 記録への反映
    // ---------------------------------------------------------------

    public function test_3つの文体が記録に保存される(): void
    {
        $record = $this->makeRecord(residentName: '佐藤 ハナ', rawNote: '入浴介助を実施');

        $this->useCase->handle($record);

        $fresh = $record->fresh();

        $this->assertNotNull($fresh->record_text);
        $this->assertNotNull($fresh->family_text);
        $this->assertNotNull($fresh->handover_note);
        $this->assertNotSame($fresh->record_text, $fresh->family_text);
    }

    public function test_音声入力の原文は書き換えられない(): void
    {
        // AIが何を変えたのかを検証できない記録は、法定文書として使えない
        $rawNote = 'えーっと今日は入浴のとき右足あがり悪くて腰支えた';
        $record = $this->makeRecord(residentName: '佐藤 ハナ', rawNote: $rawNote);

        $this->useCase->handle($record);

        $this->assertSame($rawNote, $record->fresh()->raw_note);
    }

    public function test_職員が確認するまで記録は確定しない(): void
    {
        $record = $this->makeRecord(residentName: '佐藤 ハナ', rawNote: '入浴介助を実施');

        $this->useCase->handle($record);

        $fresh = $record->fresh();

        $this->assertNull($fresh->confirmed_at);
        $this->assertTrue($fresh->hasUnconfirmedAiDraft() || $fresh->llm_job_id === null);
        $this->assertFalse($fresh->record_text_edited_by_human);
    }

    // ---------------------------------------------------------------
    // 口頭連絡タスク（F-20）
    // ---------------------------------------------------------------

    public function test_口頭連絡が必要な事項はタスクとして発行される(): void
    {
        $record = $this->makeRecord(residentName: '佐藤 ハナ', rawNote: '食事中にむせ込みが一回あった');

        $this->useCase->handle($record);

        $task = VerbalContactTask::sole();

        $this->assertSame('食事中のむせ込み', $task->topic);
        $this->assertSame('same_day', $task->urgency);
        $this->assertSame('llm', $task->source);
        $this->assertSame($record->id, $task->service_record_id);
    }

    public function test_むせ込みはご家族向け文書にも記載される(): void
    {
        // 口頭連絡タスクを出すことは、文書から省く理由にはならない
        $record = $this->makeRecord(residentName: '佐藤 ハナ', rawNote: '食事中にむせ込みが一回あった');

        $result = $this->useCase->handle($record);

        $this->assertStringContainsString('むせ込み', (string) $result['family_text']);
    }

    // ---------------------------------------------------------------
    // 構造化データの抽出
    // ---------------------------------------------------------------

    public function test_話し言葉から摂取割合が抽出されフォームへ反映される(): void
    {
        $record = $this->makeRecord(residentName: '佐藤 ハナ', rawNote: '昼ごはん半分くらい');

        $this->useCase->handle($record);

        $meal = $record->fresh()->mealRecords()->where('meal_type', 'lunch')->sole();

        $this->assertSame(50, $meal->staple_rate);
    }

    public function test_職員が入力済みの値はaiで上書きしない(): void
    {
        // 人が実際に見て入力した値のほうが確かである
        $record = $this->makeRecord(residentName: '佐藤 ハナ', rawNote: '水分たくさん飲まれた');
        $record->update(['total_water_ml' => 960]);

        $this->fake->queueParsed($this->response([
            'detected_items' => [
                'meal_staple_rate' => null,
                'meal_side_rate' => null,
                'water_ml' => 1500,
                'bathing_type' => 'bath',
                'incident_suspected' => false,
            ],
        ]));

        $this->useCase->handle($record);

        $this->assertSame(960, $record->fresh()->total_water_ml);
    }

    public function test_原文が空なら実行されない(): void
    {
        $record = $this->makeRecord(residentName: '佐藤 ハナ', rawNote: '   ');

        $this->expectException(\RuntimeException::class);

        try {
            $this->useCase->handle($record);
        } finally {
            $this->assertSame(0, $this->fake->callCount());
        }
    }

    // ---------------------------------------------------------------

    private function makeRecord(
        string $residentName,
        string $rawNote,
        ?string $staffName = null,
        ?Facility $facility = null,
    ): ServiceRecord {
        $facility ??= Facility::factory()->create();

        $resident = Resident::factory()->for($facility)->create([
            'name' => $residentName,
            'name_kana' => 'サトウ ハナ',
        ]);

        $staff = User::factory()->create([
            'facility_id' => $facility->id,
            'name' => $staffName ?? '河野 直樹',
        ]);

        return ServiceRecord::factory()->for($resident)->create([
            'recorded_by' => $staff->id,
            'raw_note' => $rawNote,
            'record_text' => null,
            'family_text' => null,
            'handover_note' => null,
            'total_water_ml' => null,
            'confirmed_at' => null,
        ]);
    }

    /**
     * スキーマを満たす応答を作る。差し替えたい項目だけ渡す。
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function response(array $overrides = []): array
    {
        return array_replace([
            'record_text' => '入浴時、浴槽をまたぐ際に右足の挙上が不十分であり、腰部を支持して介助を実施。',
            'family_text' => '本日もお元気にお過ごしでした。',
            'handover_note' => '入浴は腰部支持での介助を継続。',
            'requires_verbal_contact' => [],
            'family_excluded' => [],
            'uncertain' => [],
            'detected_items' => [
                'meal_staple_rate' => null,
                'meal_side_rate' => null,
                'water_ml' => null,
                'bathing_type' => 'bath',
                'incident_suspected' => false,
            ],
        ], $overrides);
    }
}
