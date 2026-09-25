<?php

namespace Tests\Feature;

use App\Enums\LlmErrorType;
use App\Enums\LlmFeature;
use App\Enums\LlmJobStatus;
use App\Enums\NoteInputMethod;
use App\Jobs\RunLlmFeature;
use App\Llm\Clients\FakeClient;
use App\Llm\Contracts\LlmClient;
use App\Llm\Data\LlmRequest as LlmRequestData;
use App\Llm\Data\LlmResponse;
use App\Llm\Exceptions\LlmException;
use App\Models\CarePlan;
use App\Models\CarePlanGoal;
use App\Models\Facility;
use App\Models\GoalProgressReport;
use App\Models\LlmJob;
use App\Models\LlmRequest;
use App\Models\Resident;
use App\Models\RiskAssessment;
use App\Models\ServiceRecord;
use App\Models\User;
use App\Models\VerbalContactTask;
use App\Support\QueueHeartbeat;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use LogicException;
use Mockery;
use Tests\TestCase;

/**
 * AI機能を実行するキューのジョブ（F-LLM-06）。
 *
 * 【確かめること】
 * ジョブは llm_jobs の行を読み直して機能を実行し、結果も失敗もその行に残す。
 * 例外を外へ出さない。出しても見る人のいない failed_jobs に溜まるだけで、
 * 職員には何も伝わらない。
 *
 * 【二重に動かさない】
 * 同じジョブが二度配られることも、ワーカーが止まっていたあいだに押された
 * ジョブが復旧後にまとめて動くこともある。どちらもAPIに課金される。
 */
class RunLlmFeatureJobTest extends TestCase
{
    use RefreshDatabase;

    private Facility $facility;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();

        $this->facility = Facility::factory()->create();
        $this->staff = User::factory()->create(['facility_id' => $this->facility->id]);
    }

    // ---------------------------------------------------------------
    // 各機能の実行
    // ---------------------------------------------------------------

    public function test_リスク兆候抽出を実行して成功を記録する(): void
    {
        $resident = Resident::factory()->for($this->facility)->create();
        $job = $this->queuedJob(LlmFeature::RiskDetection, $resident, withPeriod: true);

        $this->execute($job);

        $job->refresh();
        $assessment = RiskAssessment::query()->sole();

        $this->assertSame(LlmJobStatus::Succeeded, $job->status);
        $this->assertSame(1, $job->attempts);
        $this->assertNotNull($job->started_at);
        $this->assertNotNull($job->finished_at);
        // 結果は生成物の所在。本文は各テーブルが持っている。
        $this->assertSame($assessment->id, $job->result['risk_assessment_id'] ?? null);
        $this->assertSame($job->id, $assessment->llm_job_id);
    }

    public function test_音声の三面変換を実行して記録へ反映する(): void
    {
        $record = $this->recordWithRawNote('えーっと 入浴のとき 浴槽またぐの 右足あがり悪くて');
        $job = $this->queuedJob(LlmFeature::VoiceTransform, $record);

        $this->execute($job);

        $job->refresh();
        $record->refresh();

        $this->assertSame(LlmJobStatus::Succeeded, $job->status);
        $this->assertSame($record->id, $job->result['service_record_id'] ?? null);
        $this->assertNotNull($record->record_text);
        $this->assertSame($job->id, $record->llm_job_id);
        // AIが書いた下書きは職員が確認するまで確定しない
        $this->assertNull($record->confirmed_at);
    }

    public function test_目標進捗要約を実行して報告を作る(): void
    {
        $resident = Resident::factory()->for($this->facility)->create();
        $plan = CarePlan::factory()->for($resident)->create();
        CarePlanGoal::factory()->count(2)->for($plan)->create();

        $job = $this->queuedJob(LlmFeature::GoalProgress, $resident, withPeriod: true);

        $this->execute($job);

        $job->refresh();
        $report = GoalProgressReport::query()->sole();

        $this->assertSame(LlmJobStatus::Succeeded, $job->status);
        $this->assertSame($report->id, $job->result['goal_progress_report_id'] ?? null);
        $this->assertSame($job->id, $report->llm_job_id);
    }

    // ---------------------------------------------------------------
    // 前提が崩れたとき
    // ---------------------------------------------------------------

    public function test_計画書が終了していれば前提不足として失敗する(): void
    {
        // 押してから実行されるまでのあいだに計画書が終わることはありうる。
        // APIを呼ばずに、アプリが日本語で書いた理由をそのまま残す。
        $resident = Resident::factory()->for($this->facility)->create();
        $job = $this->queuedJob(LlmFeature::GoalProgress, $resident, withPeriod: true);

        $this->execute($job);

        $job->refresh();

        $this->assertSame(LlmJobStatus::Failed, $job->status);
        $this->assertSame('precondition_failed', $job->error_type);
        $this->assertStringContainsString('通所介護計画書', (string) $job->error_message);
        $this->assertSame($job->error_message, $job->userFacingError());
        $this->assertSame(0, LlmRequest::query()->count(), 'APIは呼ばれていない');
    }

    public function test_対象が削除されていれば前提不足として失敗する(): void
    {
        $resident = Resident::factory()->for($this->facility)->create();
        $job = $this->queuedJob(LlmFeature::RiskDetection, $resident, withPeriod: true);

        $resident->delete();

        $this->execute($job);

        $job->refresh();

        $this->assertSame(LlmJobStatus::Failed, $job->status);
        $this->assertSame('precondition_failed', $job->error_type);
        $this->assertSame(0, LlmRequest::query()->count(), 'APIは呼ばれていない');
    }

    // ---------------------------------------------------------------
    // LLMの失敗
    // ---------------------------------------------------------------

    public function test_llmの失敗は種別つきで記録され例外を外へ出さない(): void
    {
        $this->mock(LlmClient::class, function (Mockery\MockInterface $mock): void {
            $mock->shouldReceive('send')->andThrow(LlmException::rateLimited('429 Too Many Requests'));
        });

        $resident = Resident::factory()->for($this->facility)->create();
        $job = $this->queuedJob(LlmFeature::RiskDetection, $resident, withPeriod: true);

        $this->execute($job);

        $job->refresh();

        $this->assertSame(LlmJobStatus::Failed, $job->status);
        $this->assertSame('rate_limit_error', $job->error_type);
        $this->assertSame('混み合っています。自動で再試行します。', $job->userFacingError());
    }

    public function test_想定外の失敗はサーバーエラーとして記録しログに残す(): void
    {
        // 職員に技術的な内容を見せても対処できない。定型文を出し、詳細はログへ。
        Log::spy();

        $this->mock(LlmClient::class, function (Mockery\MockInterface $mock): void {
            $mock->shouldReceive('send')->andThrow(new LogicException('unexpected'));
        });

        $resident = Resident::factory()->for($this->facility)->create();
        $job = $this->queuedJob(LlmFeature::RiskDetection, $resident, withPeriod: true);

        $this->execute($job);

        $job->refresh();

        $this->assertSame(LlmJobStatus::Failed, $job->status);
        $this->assertSame('api_error', $job->error_type);
        Log::shouldHaveReceived('error')->once();
    }

    public function test_ワーカーに打ち切られたら失敗として記録する(): void
    {
        // 応答を待っている最中にプロセスごと止められると handle() では
        // 捕まえられない。行が running のまま残ると画面は永遠に「実行中」を出す。
        $resident = Resident::factory()->for($this->facility)->create();
        $job = $this->queuedJob(LlmFeature::RiskDetection, $resident, withPeriod: true, overrides: [
            'status' => LlmJobStatus::Running,
            'started_at' => now(),
        ]);

        (new RunLlmFeature($job->id))->failed(new TimeoutExceededException('timed out'));

        $job->refresh();

        $this->assertSame(LlmJobStatus::Failed, $job->status);
        $this->assertSame('timeout', $job->error_type);
        $this->assertNotNull($job->finished_at);
    }

    // ---------------------------------------------------------------
    // 二重に動かさない
    // ---------------------------------------------------------------

    public function test_すでに終わったジョブは二重に実行しない(): void
    {
        $this->mock(LlmClient::class, function (Mockery\MockInterface $mock): void {
            $mock->shouldNotReceive('send');
        });

        $resident = Resident::factory()->for($this->facility)->create();
        $job = $this->queuedJob(LlmFeature::RiskDetection, $resident, withPeriod: true, overrides: [
            'status' => LlmJobStatus::Succeeded,
            'finished_at' => now()->subMinute(),
        ]);

        $this->execute($job);

        $this->assertSame(LlmJobStatus::Succeeded, $job->refresh()->status);
        $this->assertSame(0, RiskAssessment::query()->count());
    }

    public function test_長く放置されたジョブは実行せず失敗として残す(): void
    {
        // ワーカーが止まっていたあいだに押された分が、復旧後にまとめて動くと
        // 職員が別の手段で済ませた処理にまで費用がかかる。
        $this->mock(LlmClient::class, function (Mockery\MockInterface $mock): void {
            $mock->shouldNotReceive('send');
        });

        $resident = Resident::factory()->for($this->facility)->create();
        $job = $this->queuedJob(LlmFeature::RiskDetection, $resident, withPeriod: true, overrides: [
            'created_at' => now()->subSeconds(LlmJob::ABANDON_QUEUED_AFTER_SECONDS + 1),
        ]);

        $this->execute($job);

        $job->refresh();

        $this->assertSame(LlmJobStatus::Failed, $job->status);
        $this->assertSame('queue_unavailable', $job->error_type);
    }

    public function test_行が消えていれば何もしない(): void
    {
        $this->mock(LlmClient::class, function (Mockery\MockInterface $mock): void {
            $mock->shouldNotReceive('send');
        });

        $this->execute(new RunLlmFeature(999999));

        $this->assertSame(0, LlmRequest::query()->count());
    }

    // ---------------------------------------------------------------
    // 中止（押し間違いの取り消し）
    // ---------------------------------------------------------------

    public function test_待機中に中止されたジョブはapiを呼ばない(): void
    {
        $this->mock(LlmClient::class, function (Mockery\MockInterface $mock): void {
            $mock->shouldNotReceive('send');
        });

        $resident = Resident::factory()->for($this->facility)->create();
        $job = $this->queuedJob(LlmFeature::RiskDetection, $resident, withPeriod: true);
        $job->cancel();

        $this->execute($job);

        $job->refresh();

        $this->assertSame(LlmJobStatus::Cancelled, $job->status);
        $this->assertSame(0, $job->attempts);
        $this->assertSame(0, RiskAssessment::query()->count());
    }

    public function test_拾ってから始めるまでのあいだに中止されたら始めない(): void
    {
        // ワーカーは行を読んでから実行中に進める。そのあいだに通った中止を、
        // 読んだときの値で上書きしてはいけない。
        $resident = Resident::factory()->for($this->facility)->create();
        $job = $this->queuedJob(LlmFeature::RiskDetection, $resident, withPeriod: true);

        $seenByWorker = LlmJob::query()->findOrFail($job->id);
        $job->cancel();

        $this->assertFalse($seenByWorker->markRunning());
        $this->assertSame(LlmJobStatus::Cancelled, $seenByWorker->status, '手元の値も行に合わせる');
        $this->assertSame(LlmJobStatus::Cancelled, $job->refresh()->status);
    }

    public function test_応答を待つあいだに中止されたら結果を記録へ書き込まない(): void
    {
        // AIの書き直しは、職員が直した文章を置き換えて確定も外す。
        // 押し間違いに気づいて止めたのに、あとから上書きされてはいけない。
        $record = $this->recordWithRawNote('えーっと 入浴のとき 浴槽またぐの 右足あがり悪くて');
        $record->forceFill([
            'record_text' => '職員が直した記録の文章',
            'record_text_edited_by_human' => true,
            'confirmed_at' => now(),
        ])->save();

        $job = $this->queuedJob(LlmFeature::VoiceTransform, $record);
        $this->cancelWhileWaiting($job);

        $this->execute($job);

        $job->refresh();
        $record->refresh();

        $this->assertSame(LlmJobStatus::Cancelled, $job->status);
        $this->assertNull($job->error_type, '失敗としては記録しない');
        $this->assertSame('職員が直した記録の文章', $record->record_text);
        $this->assertTrue($record->record_text_edited_by_human);
        $this->assertNotNull($record->confirmed_at, '確定も外れない');
        $this->assertNull($record->llm_job_id);
        $this->assertSame(0, VerbalContactTask::query()->count(), '口頭連絡のタスクも作らない');
        $this->assertSame(1, LlmRequest::query()->count(), '送ってしまった1回は費用として残る');
    }

    public function test_リスク兆候抽出も中止されたら結果を残さない(): void
    {
        // 画面には前回の結果が表示されたまま残る
        $resident = Resident::factory()->for($this->facility)->create();
        $job = $this->queuedJob(LlmFeature::RiskDetection, $resident, withPeriod: true);
        $this->cancelWhileWaiting($job);

        $this->execute($job);

        $this->assertSame(LlmJobStatus::Cancelled, $job->refresh()->status);
        $this->assertSame(0, RiskAssessment::query()->count());
    }

    public function test_中止されたら再送しない(): void
    {
        // 再送を待つあいだに押された中止でも、次の送信はしない。
        // 結果を使わないのに、送った分だけ課金される。
        $resident = Resident::factory()->for($this->facility)->create();
        $job = $this->queuedJob(LlmFeature::RiskDetection, $resident, withPeriod: true);

        $this->mock(LlmClient::class, function (Mockery\MockInterface $mock) use ($job): void {
            $mock->shouldReceive('send')->once()->andReturnUsing(function () use ($job): never {
                LlmJob::query()->findOrFail($job->id)->cancel();

                throw LlmException::rateLimited('429 Too Many Requests');
            });
        });

        $this->execute($job);

        $job->refresh();

        $this->assertSame(LlmJobStatus::Cancelled, $job->status);
        $this->assertNull($job->error_type);
    }

    public function test_中止のあとに起きた失敗は記録しない(): void
    {
        // 職員が止めたものを失敗と表示すると、「管理者にご連絡ください」の
        // 案内まで出てしまう。
        $resident = Resident::factory()->for($this->facility)->create();
        $job = $this->queuedJob(LlmFeature::RiskDetection, $resident, withPeriod: true);

        $this->mock(LlmClient::class, function (Mockery\MockInterface $mock) use ($job): void {
            $mock->shouldReceive('send')->once()->andReturnUsing(function () use ($job): never {
                LlmJob::query()->findOrFail($job->id)->cancel();

                throw new LlmException(LlmErrorType::Authentication, '401 Unauthorized');
            });
        });

        $this->execute($job);

        $job->refresh();

        $this->assertSame(LlmJobStatus::Cancelled, $job->status);
        $this->assertNull($job->error_type);
        $this->assertNull($job->userFacingError());
    }

    public function test_結果を書き込んだあとは中止できない(): void
    {
        // 間に合わなかった中止を「中止しました」と見せると、職員は反映された
        // 文章を確かめない。
        $record = $this->recordWithRawNote('午前中は体操に参加');
        $job = $this->queuedJob(LlmFeature::VoiceTransform, $record);

        $this->execute($job);

        $this->assertFalse($job->cancel());
        $this->assertSame(LlmJobStatus::Succeeded, $job->refresh()->status);
        $this->assertSame($record->id, $job->result['service_record_id'] ?? null);
    }

    // ---------------------------------------------------------------
    // 運用のための振る舞い
    // ---------------------------------------------------------------

    public function test_実行のたびにワーカーの稼働時刻を残す(): void
    {
        // ワーカーが止まっていることに、画面から気づけるようにする
        $this->assertNull(QueueHeartbeat::lastSeenAt());

        $resident = Resident::factory()->for($this->facility)->create();
        $this->execute($this->queuedJob(LlmFeature::RiskDetection, $resident, withPeriod: true));

        $this->assertNotNull(QueueHeartbeat::lastSeenAt());
    }

    public function test_再試行はゲートウェイに任せる(): void
    {
        // Laravel 側でもやり直すと、同じAPI呼び出しに二重で課金される
        $job = new RunLlmFeature(1);

        $this->assertSame(1, $job->tries);
        $this->assertSame((int) config('llm.job_timeout'), $job->timeout);
        $this->assertLessThan(90, $job->timeout, 'Flex ワーカーの猶予（90秒）より短い');
    }

    // ---------------------------------------------------------------

    private function execute(RunLlmFeature|LlmJob $jobOrRow): void
    {
        $job = $jobOrRow instanceof LlmJob ? new RunLlmFeature($jobOrRow->id) : $jobOrRow;

        $this->app->call([$job, 'handle']);
    }

    /** @param array<string, mixed> $overrides */
    private function queuedJob(LlmFeature $feature, Model $target, bool $withPeriod = false, array $overrides = []): LlmJob
    {
        return LlmJob::factory()->create([
            'feature' => $feature,
            'target_type' => $target->getMorphClass(),
            'target_id' => $target->getKey(),
            'period_from' => $withPeriod ? today()->subMonths(3) : null,
            'period_to' => $withPeriod ? today() : null,
            'status' => LlmJobStatus::Queued,
            'requested_by' => $this->staff->id,
            ...$overrides,
        ]);
    }

    /**
     * APIの応答を待っているあいだに、職員が画面から中止した状況を作る。
     * 応答そのものは FakeClient の既定の内容をそのまま返す。
     */
    private function cancelWhileWaiting(LlmJob $job): void
    {
        $this->mock(LlmClient::class, function (Mockery\MockInterface $mock) use ($job): void {
            $mock->shouldReceive('send')->once()->andReturnUsing(function (LlmRequestData $request) use ($job): LlmResponse {
                LlmJob::query()->findOrFail($job->id)->cancel();

                return (new FakeClient)->send($request);
            });
        });
    }

    private function recordWithRawNote(string $rawNote): ServiceRecord
    {
        $resident = Resident::factory()->for($this->facility)->create();

        $record = $resident->serviceRecords()->create([
            'recorded_by' => $this->staff->id,
            'service_date' => today(),
            'attendance_status' => 'attended',
        ]);

        $record->notes()->create([
            'recorded_by' => $this->staff->id,
            'body' => $rawNote,
            'input_method' => NoteInputMethod::Voice,
        ]);

        return $record;
    }
}
