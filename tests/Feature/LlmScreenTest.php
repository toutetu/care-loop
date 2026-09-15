<?php

namespace Tests\Feature;

use App\Enums\LlmErrorType;
use App\Enums\LlmFeature;
use App\Enums\LlmJobStatus;
use App\Enums\NoteInputMethod;
use App\Enums\UserRole;
use App\Jobs\RunLlmFeature;
use App\Llm\Contracts\LlmClient;
use App\Llm\Exceptions\LlmException;
use App\Models\Facility;
use App\Models\LlmJob;
use App\Models\LlmRequest;
use App\Models\Resident;
use App\Models\ServiceRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * AI利用ログの画面と、画面からのAI実行。
 *
 * 確かめたいのは、失敗したときに画面が壊れないことと、
 * 職員に「次に何をすればよいか」が伝わることである。
 *
 * 【実行はキューで行う】
 * 画面は llm_jobs に行を作って積み、すぐに戻る。テストの既定はキューが
 * sync なので、積んだ直後にジョブがその場で動き、結果は行に残る。
 * 積むところまでを確かめたいテストでは Queue::fake() で止める。
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

        // 再送のバックオフで実際に待たせない
        Sleep::fake();

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
    // 画面からのAI実行 — 受け付けて、すぐ戻る
    // ---------------------------------------------------------------

    public function test_実行はキューへ積んで即座に画面へ戻る(): void
    {
        // 結果を待たない。LLMの応答は数十秒かかり、リクエストの中で待つと
        // 手前のゲートウェイに切られて英語のエラーページだけが残る。
        Queue::fake();

        $resident = Resident::factory()->for($this->facility)->create();

        $this->actingAs($this->staff)
            ->from(route('residents.show', $resident))
            ->post(route('llm.risk-detection', $resident))
            ->assertRedirect(route('residents.show', $resident))
            ->assertSessionHas('success', LlmFeature::RiskDetection->acceptedMessage());

        $job = LlmJob::query()->sole();

        $this->assertSame(LlmJobStatus::Queued, $job->status);
        $this->assertSame($this->staff->id, $job->requested_by);
        // 期間は行が持つ。ワーカーは行を読み直して実行する。
        $this->assertTrue($job->period_from?->isSameDay(today()->subMonths(3)) ?? false);
        $this->assertTrue($job->period_to?->isSameDay(today()) ?? false);

        Queue::assertPushed(RunLlmFeature::class, fn (RunLlmFeature $pushed) => $pushed->llmJobId === $job->id);
    }

    public function test_実行に失敗しても画面は壊れず日本語で理由が出る(): void
    {
        $resident = Resident::factory()->for($this->facility)->create();

        $this->mock(LlmClient::class, function (Mockery\MockInterface $mock): void {
            $mock->shouldReceive('send')
                ->andThrow(LlmException::rateLimited('429 Too Many Requests'));
        });

        // 受け付け自体は成功する。失敗はジョブに残り、押した画面の
        // 進捗表示から職員に伝わる（キューが sync なのでその場で動いている）。
        $this->actingAs($this->staff)
            ->from(route('residents.show', $resident))
            ->post(route('llm.risk-detection', $resident))
            ->assertRedirect(route('residents.show', $resident));

        $this->actingAs($this->staff)->get(route('residents.show', $resident))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('llmJobs.riskDetection.status', 'failed')
                ->where('llmJobs.riskDetection.isActive', false)
                ->where('llmJobs.riskDetection.errorMessage', '混み合っています。自動で再試行します。')
            );
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
        $this->assertSame('schema_mismatch', $job->error_type);
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
        Queue::fake();

        $record = $this->recordWithRawNote(null);

        $this->actingAs($this->staff)->post(route('llm.voice-transform', $record), [
            'raw_note' => 'えーっと 午前中は体操に参加されて',
        ]);

        // 原文は変換の前に保存される。AIが何を変えたのかを後から検証するには、
        // 変換に使った文章が残っている必要がある。
        $this->assertSame(
            'えーっと 午前中は体操に参加されて',
            $record->refresh()->load('notes')->combinedNoteText(),
        );

        $this->assertSame(1, LlmJob::query()->count());
        Queue::assertPushed(RunLlmFeature::class);
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
    // 二重に積まない
    // ---------------------------------------------------------------

    public function test_同じ対象に実行中のジョブがあれば二重に積まない(): void
    {
        // 同期実行のころは処理中にボタンを押せなかった。非同期にすると
        // 押した回数だけ積まれ、その数だけAPIに課金される。
        Queue::fake();

        $resident = Resident::factory()->for($this->facility)->create();
        $this->queuedJob(LlmFeature::RiskDetection, $resident);

        $this->actingAs($this->staff)
            ->from(route('residents.show', $resident))
            ->post(route('llm.risk-detection', $resident))
            ->assertRedirect(route('residents.show', $resident))
            ->assertSessionHas('error', 'リスク兆候抽出はすでに実行中です。完了までお待ちください。');

        $this->assertSame(1, LlmJob::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_別の機能なら同じ対象でも受け付ける(): void
    {
        Queue::fake();

        $resident = Resident::factory()->for($this->facility)->create();
        $this->queuedJob(LlmFeature::GoalProgress, $resident);

        $this->actingAs($this->staff)
            ->post(route('llm.risk-detection', $resident))
            ->assertSessionHas('success');

        $this->assertSame(2, LlmJob::query()->count());
    }

    public function test_終わったジョブは二重投入の判定に使わない(): void
    {
        Queue::fake();

        $resident = Resident::factory()->for($this->facility)->create();
        $this->queuedJob(LlmFeature::RiskDetection, $resident, ['status' => LlmJobStatus::Succeeded]);

        $this->actingAs($this->staff)
            ->post(route('llm.risk-detection', $resident))
            ->assertSessionHas('success');

        $this->assertSame(2, LlmJob::query()->count());
    }

    public function test_長く放置されたジョブは二重投入の判定に使わない(): void
    {
        // ワーカーが止まっていたあいだに押した分が、復旧後もずっと
        // 「実行中です」と弾き続けてはいけない。
        Queue::fake();

        $resident = Resident::factory()->for($this->facility)->create();
        $this->queuedJob(LlmFeature::RiskDetection, $resident, [
            'created_at' => now()->subSeconds(LlmJob::ABANDON_QUEUED_AFTER_SECONDS + 1),
        ]);

        $this->actingAs($this->staff)
            ->post(route('llm.risk-detection', $resident))
            ->assertSessionHas('success');

        $this->assertSame(2, LlmJob::query()->count());
    }

    // ---------------------------------------------------------------
    // キューに届かない
    // ---------------------------------------------------------------

    public function test_キューへ積めなければ失敗として記録し日本語で知らせる(): void
    {
        // 行を待機中のまま残すと、画面はいつまでも「実行中」を出し続ける
        Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('queue unreachable'));

        $resident = Resident::factory()->for($this->facility)->create();

        $this->actingAs($this->staff)
            ->from(route('residents.show', $resident))
            ->post(route('llm.risk-detection', $resident))
            ->assertRedirect(route('residents.show', $resident))
            ->assertSessionHas('error', LlmErrorType::QueueUnavailable->userMessage());

        $job = LlmJob::query()->sole();

        $this->assertSame(LlmJobStatus::Failed, $job->status);
        $this->assertSame('queue_unavailable', $job->error_type);
    }

    // ---------------------------------------------------------------
    // 進捗の表示
    // ---------------------------------------------------------------

    public function test_実行中のジョブは画面へ渡り進捗表示に使う(): void
    {
        $resident = Resident::factory()->for($this->facility)->create();
        $this->queuedJob(LlmFeature::RiskDetection, $resident);

        $this->actingAs($this->staff)->get(route('residents.show', $resident))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('llmJobs.riskDetection.isActive', true)
                ->where('llmJobs.riskDetection.isDelayed', false)
                ->where('llmJobs.riskDetection.statusLabel', '待機中')
                ->where('llmJobs.goalProgress', null)
            );
    }

    public function test_記録の画面にも変換の進捗が渡る(): void
    {
        $record = $this->recordWithRawNote('午前中は体操に参加');
        $this->queuedJob(LlmFeature::VoiceTransform, $record, ['status' => LlmJobStatus::Running, 'started_at' => now()]);

        $this->actingAs($this->staff)->get(route('records.edit', $record))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('llmJob.isActive', true)
                ->where('llmJob.statusLabel', '実行中')
            );
    }

    public function test_待機が長引いたジョブには警告を添える(): void
    {
        // 「待てば終わる」と「待っても終わらない」を同じ見た目にしない
        $resident = Resident::factory()->for($this->facility)->create();
        $this->queuedJob(LlmFeature::RiskDetection, $resident, [
            'created_at' => now()->subSeconds(LlmJob::DELAYED_AFTER_SECONDS + 1),
        ]);

        $this->actingAs($this->staff)->get(route('residents.show', $resident))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('llmJobs.riskDetection.isActive', true)
                ->where('llmJobs.riskDetection.isDelayed', true)
            );
    }

    public function test_見捨てたジョブは失敗として画面に出す(): void
    {
        $resident = Resident::factory()->for($this->facility)->create();
        $this->queuedJob(LlmFeature::RiskDetection, $resident, [
            'created_at' => now()->subSeconds(LlmJob::ABANDON_QUEUED_AFTER_SECONDS + 1),
        ]);

        $this->actingAs($this->staff)->get(route('residents.show', $resident))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('llmJobs.riskDetection.status', 'failed')
                ->where('llmJobs.riskDetection.isActive', false)
                ->where('llmJobs.riskDetection.errorMessage', LlmErrorType::QueueUnavailable->userMessage())
                ->where('llmJobs.riskDetection.needsOperatorAttention', true)
            );
    }

    public function test_完了したジョブには完了の文言が付く(): void
    {
        $resident = Resident::factory()->for($this->facility)->create();
        $this->queuedJob(LlmFeature::RiskDetection, $resident, [
            'status' => LlmJobStatus::Succeeded,
            'finished_at' => now(),
        ]);

        $this->actingAs($this->staff)->get(route('residents.show', $resident))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('llmJobs.riskDetection.completedMessage', LlmFeature::RiskDetection->completedMessage())
                ->where('llmJobs.riskDetection.errorMessage', null)
            );
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

    /** @param array<string, mixed> $overrides */
    private function queuedJob(LlmFeature $feature, Model $target, array $overrides = []): LlmJob
    {
        return LlmJob::factory()->create([
            'feature' => $feature,
            'target_type' => $target->getMorphClass(),
            'target_id' => $target->getKey(),
            'status' => LlmJobStatus::Queued,
            'requested_by' => $this->staff->id,
            ...$overrides,
        ]);
    }
}
