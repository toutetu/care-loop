<?php

namespace App\Http\Controllers;

use App\Enums\LlmFeature;
use App\Enums\LlmJobStatus;
use App\Llm\Exceptions\LlmException;
use App\Llm\UseCases\DetectRisks;
use App\Llm\UseCases\SummarizeGoalProgress;
use App\Llm\UseCases\TransformVoiceNote;
use App\Models\LlmJob;
use App\Models\Resident;
use App\Models\ServiceRecord;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * 画面からAI機能を実行する入口。
 *
 * 【失敗しても画面を壊さない】
 * LLMは失敗する。混み合っていることも、こちらの求める形で返ってこないことも
 * ある。どの失敗も、職員には「次に何をすればよいか」が分かる日本語で返す。
 * 例外の種別ごとの文面は LlmErrorType が持っており、ここでは分岐しない
 * （要件定義 7.4節）。
 *
 * 【実行者を必ず残す】
 * 1回ごとに費用が発生する。誰がどのご利用者に対して呼び出したのかを
 * llm_jobs に残し、AI利用ログの画面から追えるようにしている。
 *
 * 【同期実行である】
 * 本来はキューに積むべきで、その設計は F-LLM-06 として定義してある。
 * 現時点では実行の結果をその場で画面に返すことを優先し、同期で呼んでいる。
 */
class LlmActionController extends Controller
{
    /** 抽出・要約の対象期間。通所介護のモニタリングの単位に合わせる。 */
    private const PERIOD_MONTHS = 3;

    /**
     * F-LLM-05 音声入力の三面変換。
     *
     * 音声の原文から、記録用・ご家族向け・申し送り用の3つの文体を一度に作る。
     */
    public function transformVoice(
        Request $request,
        ServiceRecord $serviceRecord,
        TransformVoiceNote $useCase,
    ): RedirectResponse {
        Gate::authorize('update', $serviceRecord);

        if (trim((string) $serviceRecord->raw_note) === '') {
            return back()->with('error', '先に音声入力または原文の入力を行ってください。');
        }

        return $this->run(
            LlmFeature::VoiceTransform,
            $serviceRecord,
            $request,
            fn (LlmJob $job) => $useCase->handle($serviceRecord, $job),
            '記録・ご家族向け・申し送りの3つの文章を生成しました。内容をご確認ください。',
        );
    }

    /**
     * F-LLM-02 リスク兆候抽出。
     *
     * 数値で判定できるものはルールベースで先に算出し、記述からしか分からない
     * 変化だけをLLMに任せる（要件定義 7.1節）。
     */
    public function detectRisks(
        Request $request,
        Resident $resident,
        DetectRisks $useCase,
    ): RedirectResponse {
        Gate::authorize('runLlm', $resident);

        [$from, $to] = $this->period();

        return $this->run(
            LlmFeature::RiskDetection,
            $resident,
            $request,
            fn (LlmJob $job) => $useCase->handle($resident, $from, $to, $job),
            'リスク兆候を抽出しました。根拠の記録を確認してから対応をご判断ください。',
        );
    }

    /**
     * F-LLM-01 目標進捗要約。
     */
    public function goalProgress(
        Request $request,
        Resident $resident,
        SummarizeGoalProgress $useCase,
    ): RedirectResponse {
        Gate::authorize('runLlm', $resident);

        $plan = $resident->activeCarePlan();

        if ($plan === null) {
            return back()->with('error', '有効な通所介護計画書がありません。先に計画書を作成してください。');
        }

        [$from, $to] = $this->period();

        return $this->run(
            LlmFeature::GoalProgress,
            $resident,
            $request,
            fn (LlmJob $job) => $useCase->handle($resident, $plan, $from, $to, $job),
            '目標進捗の要約を作成しました。モニタリング記録へ転記する前にご確認ください。',
        );
    }

    // ---------------------------------------------------------------

    /**
     * 実行を包む共通処理。ジョブの記録と、失敗時の画面表示をここに集約する。
     *
     * @param  callable(LlmJob): mixed  $callback
     */
    private function run(
        LlmFeature $feature,
        Model $target,
        Request $request,
        callable $callback,
        string $successMessage,
    ): RedirectResponse {
        $job = LlmJob::query()->create([
            'feature' => $feature,
            'target_type' => $target->getMorphClass(),
            'target_id' => $target->getKey(),
            'status' => LlmJobStatus::Queued,
            'requested_by' => $request->user()?->id,
        ]);

        try {
            $callback($job);

            return back()->with('success', $successMessage);
        } catch (LlmException $exception) {
            // 種別ごとの文面は列挙型が持っている。ここで分岐すると、
            // 判断基準が2か所に散らばる。
            $this->markFailed($job, $exception->errorType->value, $exception);

            return back()->with('error', $exception->userMessage());
        } catch (RuntimeException $exception) {
            $this->markFailed($job, 'invalid_request_error', $exception);

            return back()->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            // 想定していない失敗。職員に技術的な内容を見せても対処できないので、
            // 画面には定型文を出し、詳細はログへ送る。
            $this->markFailed($job, 'api_error', $exception);

            Log::error('LLMの実行に失敗しました。', [
                'job_id' => $job->id,
                'feature' => $feature->value,
                'exception' => $exception,
            ]);

            return back()->with('error', '処理に失敗しました。時間をおいてお試しください。');
        }
    }

    private function markFailed(LlmJob $job, string $errorType, Throwable $exception): void
    {
        // ゲートウェイ側ですでに失敗を記録している場合は上書きしない。
        // 再試行の回数など、より詳しい情報が入っているため。
        if ($job->fresh()?->status === LlmJobStatus::Failed) {
            return;
        }

        $job->markFailed($errorType, $exception);
    }

    /**
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    private function period(): array
    {
        return [today()->subMonths(self::PERIOD_MONTHS), today()];
    }
}
