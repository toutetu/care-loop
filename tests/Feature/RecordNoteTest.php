<?php

namespace Tests\Feature;

use App\Enums\NoteInputMethod;
use App\Enums\UserRole;
use App\Jobs\RunLlmFeature;
use App\Models\Facility;
use App\Models\LlmJob;
use App\Models\Resident;
use App\Models\ServiceRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * 音声入力の原文の「確定」。
 *
 * 確かめたいのは、確定がAIの書き直しとは別の操作であること、確定した原文が
 * 誰の入力か分かる1件として積まれること、記録そのものの確定とは混ざらない
 * ことである。
 */
class RecordNoteTest extends TestCase
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

    public function test_確定した原文は誰が入れたかと一緒に1件として積まれる(): void
    {
        $this->actingAs($this->staff)
            ->from($this->editUrl())
            ->post($this->storeUrl(), ['body' => 'えーっと 午前中は体操に参加されて'])
            ->assertRedirect($this->editUrl())
            ->assertSessionHas('success', '原文を確定しました。');

        $note = $this->record->notes()->sole();

        $this->assertSame('えーっと 午前中は体操に参加されて', $note->body);
        $this->assertSame($this->staff->id, $note->recorded_by);
    }

    public function test_画面のマイクで入れた原文は音声入力として残る(): void
    {
        // 読み返す職員が、聞き違いの混じりうる文だと分かるようにする
        $this->actingAs($this->staff)->post($this->storeUrl(), [
            'body' => '昼食は半分くらい',
            'input_method' => 'voice',
        ]);

        $this->assertSame(NoteInputMethod::Voice, $this->record->notes()->sole()->input_method);
    }

    public function test_入力方法の指定がなければ手入力として残る(): void
    {
        // OSのキーボードの音声入力は、アプリからは見分けられない
        $this->actingAs($this->staff)->post($this->storeUrl(), ['body' => '昼食は半分くらい']);

        $this->assertSame(NoteInputMethod::Keyboard, $this->record->notes()->sole()->input_method);
    }

    public function test_原文を確定しても記録そのものは確定しない(): void
    {
        // 原文の確定は「この文でよい」という入力の区切りでしかない。
        // AIが書いた文章を職員が読んだことにはならない。
        $this->actingAs($this->staff)->post($this->storeUrl(), ['body' => '午前中は体操に参加']);

        $this->assertNull($this->record->refresh()->confirmed_at);
    }

    public function test_原文を確定してもaiは呼ばない(): void
    {
        // AIで書き直すかどうかは、別のボタンで職員が決める
        Queue::fake();

        $this->actingAs($this->staff)->post($this->storeUrl(), ['body' => '午前中は体操に参加']);

        $this->assertSame(0, LlmJob::query()->count());
        Queue::assertNotPushed(RunLlmFeature::class);
    }

    public function test_同じ内容を続けて確定しても二重に積まない(): void
    {
        // 確定を押し直しただけで同じ文が2件になると、AIにも2回渡る
        $this->actingAs($this->staff)->post($this->storeUrl(), ['body' => '午前中は体操に参加']);
        $this->actingAs($this->staff)->post($this->storeUrl(), ['body' => '午前中は体操に参加']);

        $this->assertSame(1, $this->record->notes()->count());
    }

    public function test_内容が違えば別の1件として積み前の原文は書き換えない(): void
    {
        // 原文はAIが何を変えたのかを確かめるための原本。訂正も新しい1件として残す
        $this->actingAs($this->staff)->post($this->storeUrl(), ['body' => '午前中は体操に参加']);
        $this->actingAs($this->staff)->post($this->storeUrl(), ['body' => '午後はレクで塗り絵']);

        $this->assertSame(
            ['午前中は体操に参加', '午後はレクで塗り絵'],
            $this->record->notes()->pluck('body')->all(),
        );
    }

    public function test_空の原文は確定できない(): void
    {
        $this->actingAs($this->staff)
            ->from($this->editUrl())
            ->post($this->storeUrl(), ['body' => '   '])
            ->assertSessionHasErrors('body');

        $this->assertSame(0, $this->record->notes()->count());
    }

    public function test_長すぎる原文は確定できない(): void
    {
        // 音声入力を止め忘れた文がそのままAIへ渡ると、費用が跳ね上がる
        $this->actingAs($this->staff)
            ->from($this->editUrl())
            ->post($this->storeUrl(), ['body' => str_repeat('あ', 5001)])
            ->assertSessionHasErrors('body');

        $this->assertSame(0, $this->record->notes()->count());
    }

    public function test_別の事業所の記録には確定できない(): void
    {
        $outsider = User::factory()->create([
            'facility_id' => Facility::factory()->create()->id,
            'role' => UserRole::Staff,
        ]);

        $this->actingAs($outsider)
            ->post($this->storeUrl(), ['body' => '午前中は体操に参加'])
            ->assertForbidden();

        $this->assertSame(0, $this->record->notes()->count());
    }

    // ---------------------------------------------------------------

    private function editUrl(): string
    {
        return route('records.edit', $this->record);
    }

    private function storeUrl(): string
    {
        return route('records.notes.store', $this->record);
    }
}
