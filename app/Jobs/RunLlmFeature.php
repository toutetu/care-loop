<?php

namespace App\Jobs;

use App\Enums\LlmErrorType;
use App\Enums\LlmFeature;
use App\Enums\LlmJobStatus;
use App\Llm\Exceptions\LlmException;
use App\Llm\UseCases\DetectRisks;
use App\Llm\UseCases\SummarizeGoalProgress;
use App\Llm\UseCases\TransformVoiceNote;
use App\Models\LlmJob;
use App\Models\Resident;
use App\Models\ServiceRecord;
use App\Support\QueueHeartbeat;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * AI機能を1件実行するキューのジョブ（F-LLM-06）。
 *
 * 【HTTPリクエストの外で動かす】
 * LLMの応答には数十秒かかり、再試行が重なると数分に達する。リクエストの中で
 * 待つと手前のゲートウェイに切られ、職員には英語のエラーページだけが残る。
 * 画面は llm_jobs に行を作ってこのジョブを積み、すぐに戻る。
 *
 * 【持ち回るのは行のIDだけ】
 * 対象や期間は llm_jobs の行が持っている。モデルをそのまま積むと、ワーカーが
 * 動く時点では古くなっている値を使うことになる。行を読み直して実行する。
 *
 * 【再試行はゲートウェイに任せる】
 * tries は 1 に固定する。LlmGateway が失敗の種類ごとに再送を判断しており、
 * Laravel 側でもう一度やり直すと、同じAPI呼び出しに二重で課金される。
 *
 * 【失敗はすべて行に残す】
 * どの失敗も例外を外へ出さず、llm_jobs に種別つきで記録する。画面はそれを
 * 読んで日本語の案内を出す。例外を投げ直しても、見る人がいない
 * failed_jobs に溜まるだけになる。
 */
final class RunLlmFeature implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** ワーカーがこのジョブを打ち切るまでの秒数。config/llm.php の job_timeout。 */
    public int $timeout;

    public function __construct(public readonly int $llmJobId)
    {
        $this->timeout = max(1, (int) config('llm.job_timeout'));
    }

    public function handle(
        TransformVoiceNote $transformVoiceNote,
        DetectRisks $detectRisks,
        SummarizeGoalProgress $summarizeGoalProgress,
    ): void {
        // 動いていることを画面から確かめられるようにする
        QueueHeartbeat::touch();

        $job = LlmJob::query()->find($this->llmJobId);

        // 行が消えている、または別のワーカーがすでに処理した（同じジョブが
        // 二重に配られることはありうる）。二度目のAPI呼び出しをしない。
        if ($job === null || $job->status !== LlmJobStatus::Queued) {
            return;
        }

        // 長く放置されたジョブは実行しない。ワーカーが止まっていたあいだに
        // 押された分が復旧後にまとめて動くと、費用だけがかかる。
        if ($job->isAbandoned()) {
            $job->markFailed(
                LlmErrorType::QueueUnavailable->value,
                sprintf(
                    '受け付けから %d 分以上処理が始まらなかったため、実行を取りやめました。',
                    intdiv(LlmJob::ABANDON_QUEUED_AFTER_SECONDS, 60),
                ),
            );

            return;
        }

        $job->markRunning();

        try {
            $result = match ($job->feature) {
                LlmFeature::VoiceTransform => $this->transformVoice($job, $transformVoiceNote),
                LlmFeature::RiskDetection => $this->detectRisks($job, $detectRisks),
                LlmFeature::GoalProgress => $this->summarizeGoalProgress($job, $summarizeGoalProgress),
                LlmFeature::Handover,
                LlmFeature::FamilyReport => throw new RuntimeException("{$job->feature->label()}はまだ画面から実行できません。"),
            };

            $job->markSucceeded($result);
        } catch (LlmException $exception) {
            // 種別ごとの文面は列挙型が持っている。ここで分岐すると、
            // 判断基準が2か所に散らばる。
            $job->markFailed($exception->errorType->value, $exception);
        } catch (RuntimeException $exception) {
            // アプリ側が日本語で書いた理由。そのまま職員への案内になる。
            $job->markFailed(LlmErrorType::Precondition->value, $exception);
        } catch (Throwable $exception) {
            // 想定していない失敗。職員に技術的な内容を見せても対処できないので、
            // 画面には定型文を出し、詳細はログへ送る。
            $job->markFailed(LlmErrorType::ServerError->value, $exception);

            Log::error('LLMの実行に失敗しました。', [
                'job_id' => $job->id,
                'feature' => $job->feature->value,
                'exception' => $exception,
            ]);
        }
    }

    /**
     * ワーカーに打ち切られたとき。
     *
     * handle() の中で捕まえられなかった失敗はここへ来る。実際に起こりうるのは
     * timeout による打ち切りで、応答を待っている最中にプロセスごと止められる。
     * 行が running のまま残ると、画面は永遠に「実行中」を出し続ける。
     */
    public function failed(?Throwable $exception): void
    {
        $job = LlmJob::query()->find($this->llmJobId);

        if ($job === null || $job->status->isFinished()) {
            return;
        }

        $type = $exception instanceof TimeoutExceededException
            ? LlmErrorType::Timeout
            : LlmErrorType::ServerError;

        $job->markFailed($type->value, $exception?->getMessage() ?? 'ワーカーが処理を打ち切りました。');

        Log::error('LLMの実行がワーカーに打ち切られました。', [
            'job_id' => $job->id,
            'feature' => $job->feature->value,
            'exception' => $exception,
        ]);
    }

    // ---------------------------------------------------------------

    /** @return array<string, mixed> */
    private function transformVoice(LlmJob $job, TransformVoiceNote $useCase): array
    {
        $record = $this->target($job, ServiceRecord::class);

        $useCase->handle($record, $job);

        return ['service_record_id' => $record->id];
    }

    /** @return array<string, mixed> */
    private function detectRisks(LlmJob $job, DetectRisks $useCase): array
    {
        $resident = $this->target($job, Resident::class);
        [$from, $to] = $this->period($job);

        $assessment = $useCase->handle($resident, $from, $to, $job);

        return [
            'risk_assessment_id' => $assessment->id,
            'finding_count' => $assessment->findings->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function summarizeGoalProgress(LlmJob $job, SummarizeGoalProgress $useCase): array
    {
        $resident = $this->target($job, Resident::class);
        [$from, $to] = $this->period($job);

        // 画面で確かめてから積んでいるが、実行までのあいだに計画書が
        // 終了していることはありうる。
        $plan = $resident->activeCarePlan();

        if ($plan === null) {
            throw new RuntimeException('有効な通所介護計画書がありません。先に計画書を作成してください。');
        }

        $report = $useCase->handle($resident, $plan, $from, $to, $job);

        return ['goal_progress_report_id' => $report->id];
    }

    /**
     * 対象を読み直す。削除済み（論理削除を含む）なら morphTo は null を返す。
     *
     * @template TTarget of Model
     *
     * @param  class-string<TTarget>  $class
     * @return TTarget
     */
    private function target(LlmJob $job, string $class): Model
    {
        $target = $job->target;

        if (! $target instanceof $class) {
            throw new RuntimeException('対象の記録が見つかりません。削除された可能性があります。');
        }

        return $target;
    }

    /**
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    private function period(LlmJob $job): array
    {
        if ($job->period_from === null || $job->period_to === null) {
            throw new RuntimeException('対象期間が指定されていません。');
        }

        return [$job->period_from, $job->period_to];
    }
}
