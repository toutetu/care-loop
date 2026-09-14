<?php

namespace Tests\Feature;

use App\Enums\LlmFeature;
use App\Enums\LlmJobStatus;
use App\Enums\NoteInputMethod;
use App\Enums\UserRole;
use App\Llm\Contracts\LlmClient;
use App\Llm\Exceptions\LlmException;
use App\Models\Facility;
use App\Models\LlmJob;
use App\Models\LlmRequest;
use App\Models\Resident;
use App\Models\ServiceRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Mockery;
use Tests\TestCase;

/**
 * AI利用ログの画面と、画面からのAI実行。
 *
 * 確かめたいのは、失敗したときに画面が壊れないことと、
 * 職員に「次に何をすればよいか」が伝わることである。
 */
class LlmScreenTest extends TestCase
{
    use RefreshDatabase;

    private Facility $facility;

    private User $admin;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facility = Facility::factory()->create();
        $this->admin = User::factory()->create([
            'facility_id' => $this->facility->id,
            'role' => UserRole::Admin,
        ]);
        $this->staff = User::factory()->create([
            'facility_id' => $this->facility->id,
            'role' => UserRole::Staff,
        ]);
    }

    // ---------------------------------------------------------------
    // AI利用ログ
    // ---------------------------------------------------------------

    public function test_管理者は利用ログを開ける(): void
    {
        $this->actingAs($this->admin)->get('/llm-logs')
            ->assertInertia(fn (AssertableInertia $page) => $page->component('llm/index'));
    }

    public function test_一般職員は利用ログを開けない(): void
    {
        // 費用と失敗率は運営の情報であり、日々の介護業務には要らない
        $this->actingAs($this->staff)->get('/llm-logs')->assertForbidden();
    }

    public function test_失敗したリクエストが種別ごとに集計される(): void
    {
        // 「混み合っている」と「APIキーが違う」では担当者の取るべき行動が違う。
        // 再試行できる失敗と、設定を直すべき失敗を分けて出す。
        $job = LlmJob::factory()->create(['feature' => LlmFeature::RiskDetection]);

        LlmRequest::factory()->count(2)->create([
            'llm_job_id' => $job->id,
            'feature' => LlmFeature::RiskDetection,
            'status' => 'failed',
            'error_type' => 'rate_limit_error',
        ]);

        LlmRequest::factory()->create([
            'llm_job_id' => $job->id,
            'feature' => LlmFeature::RiskDetection,
            'status' => 'failed',
            'error_type' => 'authentication_error',
        ]);

        $this->actingAs($this->admin)->get('/llm-logs')
            ->assertInertia(function (AssertableInertia $page) {
                $page->has('errors', 2);

                $errors = collect($page->toArray()['props']['errors'])->keyBy('type');

                $this->assertSame(2, $errors['rate_limit_error']['count']);
                $this->assertTrue($errors['rate_limit_error']['isRetryable']);

                // 認証エラーは再試行しても成功しない。担当者の対応が要る。
                $this->assertFalse($errors['authentication_error']['isRetryable']);
                $this->assertTrue($errors['authentication_error']['needsOperatorAttention']);
            });
    }

    public function test_実際に呼び出す設定かどうかが画面に出る(): void
    {
        // APIキーが未設定のときは FakeClient が動く。課金される状態かどうかを
        // はっきりさせないと、動いているつもりで動いていないことになる。
        config(['llm.api_key' => null]);

        $this->actingAs($this->admin)->get('/llm-logs')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('summary.driver', 'fake'));
    }

    // ---------------------------------------------------------------
    // 画面からのAI実行
    // ---------------------------------------------------------------

    public function test_実行に失敗しても画面は壊れず日本語で理由が出る(): void
    {
        $resident = Resident::factory()->for($this->facility)->create();

        $this->mock(LlmClient::class, function (Mockery\MockInterface $mock): void {
            $mock->shouldReceive('send')
                ->andThrow(LlmException::rateLimited('429 Too Many Requests'));
        });

        $this->actingAs($this->staff)
            ->from(route('residents.show', $resident))
            ->post(route('llm.risk-detection', $resident))
            ->assertRedirect(route('residents.show', $resident))
            ->assertSessionHas('error', '混み合っています。自動で再試行します。');
    }

    public function test_失敗した実行はジョブに記録される(): void
    {
        // 失敗が残らないと、動いていないことに誰も気づけない
        $resident = Resident::factory()->for($this->facility)->create();

        $this->mock(LlmClient::class, function (Mockery\MockInterface $mock): void {
            $mock->shouldReceive('send')
                ->andThrow(LlmException::schemaMismatch('必須項目 evidence が欠落しています。'));
        });

        $this->actingAs($this->staff)->post(route('llm.risk-detection', $resident));

        $job = LlmJob::query()->latest('id')->first();

        $this->assertSame(LlmJobStatus::Failed, $job->status);
        $this->assertSame($this->staff->id, $job->requested_by);
    }

    public function test_別の事業所のご利用者には実行できない(): void
    {
        $outsider = Resident::factory()->for(Facility::factory()->create())->create();

        $this->actingAs($this->staff)
            ->post(route('llm.risk-detection', $outsider))
            ->assertForbidden();

        // 弾かれた実行でジョブを作らない。費用も発生していない。
        $this->assertSame(0, LlmJob::query()->count());
    }

    public function test_音声の原文がなければ実行しない(): void
    {
        // 空の入力で呼び出しても意味がなく、費用だけがかかる
        $record = $this->recordWithRawNote(null);

        $this->actingAs($this->staff)
            ->from(route('records.edit', $record))
            ->post(route('llm.voice-transform', $record))
            ->assertSessionHas('error', '先に音声入力または原文の入力を行ってください。');

        $this->assertSame(0, LlmJob::query()->count());
    }

    public function test_保存していない原文でも変換できる(): void
    {
        // 「保存してから変換」の2手順にすると、保存を忘れたまま押した職員には
        // 何も起きていないように見える。押した時点の内容で動くのが自然である。
        $record = $this->recordWithRawNote(null);

        $this->mock(LlmClient::class, function (Mockery\MockInterface $mock): void {
            $mock->shouldReceive('send')
                ->andThrow(LlmException::rateLimited('429 Too Many Requests'));
        });

        $this->actingAs($this->staff)->post(route('llm.voice-transform', $record), [
            'raw_note' => 'えーっと 午前中は体操に参加されて',
        ]);

        // 原文は変換の前に保存される。AIが何を変えたのかを後から検証するには、
        // 変換に使った文章が残っている必要がある。
        $this->assertSame(
            'えーっと 午前中は体操に参加されて',
            $record->refresh()->load('notes')->combinedNoteText(),
        );

        // 呼び出しは行われている（ここではモックが失敗を返している）
        $this->assertSame(1, LlmJob::query()->count());
    }

    public function test_通所介護計画書がなければ進捗要約を実行しない(): void
    {
        $resident = Resident::factory()->for($this->facility)->create();

        $this->actingAs($this->staff)
            ->from(route('residents.show', $resident))
            ->post(route('llm.goal-progress', $resident))
            ->assertSessionHas('error', '有効な通所介護計画書がありません。先に計画書を作成してください。');

        $this->assertSame(0, LlmJob::query()->count());
    }

    // ---------------------------------------------------------------
    // 実行はGETで起こさない
    // ---------------------------------------------------------------

    public function test_ai実行はgetでは呼び出せない(): void
    {
        // リンクを踏んだだけ、ブラウザが先読みしただけで課金される状態を作らない
        $resident = Resident::factory()->for($this->facility)->create();

        $this->actingAs($this->staff)
            ->get("/residents/{$resident->id}/risk-detection")
            ->assertStatus(405);
    }

    // ---------------------------------------------------------------

    private function recordWithRawNote(?string $rawNote): ServiceRecord
    {
        $resident = Resident::factory()->for($this->facility)->create();

        $record = $resident->serviceRecords()->create([
            'recorded_by' => $this->staff->id,
            'service_date' => today(),
            'attendance_status' => 'attended',
        ]);

        if ($rawNote !== null && trim($rawNote) !== '') {
            $record->notes()->create([
                'recorded_by' => $this->staff->id,
                'body' => $rawNote,
                'input_method' => NoteInputMethod::Voice,
            ]);
        }

        return $record;
    }
}
