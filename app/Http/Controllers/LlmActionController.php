<?php

namespace App\Http\Controllers;

use App\Enums\LlmErrorType;
use App\Enums\LlmFeature;
use App\Enums\LlmJobStatus;
use App\Enums\NoteInputMethod;
use App\Jobs\RunLlmFeature;
use App\Models\LlmJob;
use App\Models\Resident;
use App\Models\ServiceRecord;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 画面からAI機能を実行する入口。
 *
 * 【受け付けて、すぐ戻る】
 * 実行はキューのジョブ（RunLlmFeature）が行う。ここでは llm_jobs に行を
 * 作って積むだけで、結果を待たない。LLMの応答は数十秒かかり、リクエストの
 * 中で待つと手前のゲートウェイに切られて英語のエラーページだけが残る。
 * 画面は行の状態をポーリングして、完了したときに結果を描く（F-LLM-06）。
 *
 * 【同じ処理を二重に積まない】
 * 同期実行のころは処理中にボタンを押せなかった。非同期にすると押した回数
 * だけ積まれ、その数だけAPIに課金される。同じ対象・同じ機能で待機中か
 * 実行中のジョブがあれば、新しい実行を受け付けない。
 *
 * 【実行者を必ず残す】
 * 1回ごとに費用が発生する。誰がどのご利用者に対して呼び出したのかを
 * llm_jobs に残し、AI処理の実行状況の画面から追えるようにしている。
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
    public function transformVoice(Request $request, ServiceRecord $serviceRecord): RedirectResponse
    {
        Gate::authorize('update', $serviceRecord);

        // 画面で入力した原文をそのまま受け取る。
        // 「保存してから変換」の2手順にすると、保存を忘れたまま押した職員には
        // 何も起きていないように見える。押した時点の内容で動くのが自然である。
        $this->storeRawNote($request, $serviceRecord);

        $serviceRecord->load('notes');

        if ($serviceRecord->combinedNoteText() === '') {
            return back()->with('error', '先に音声入力または原文の入力を行ってください。');
        }

        return $this->enqueue(LlmFeature::VoiceTransform, $serviceRecord, $request);
    }

    /**
     * F-LLM-02 リスク兆候抽出。
     *
     * 数値で判定できるものはルールベースで先に算出し、記述からしか分からない
     * 変化だけをLLMに任せる（要件定義 7.1節）。
     */
    public function detectRisks(Request $request, Resident $resident): RedirectResponse
    {
        Gate::authorize('runLlm', $resident);

        [$from, $to] = $this->period();

        return $this->enqueue(LlmFeature::RiskDetection, $resident, $request, $from, $to);
    }

    /**
     * F-LLM-01 目標進捗要約。
     */
    public function goalProgress(Request $request, Resident $resident): RedirectResponse
    {
        Gate::authorize('runLlm', $resident);

        // 材料が無いことは積む前に分かる。積んでから失敗させると、
        // 職員は数秒待たされたうえで同じことを知らされる。
        if ($resident->activeCarePlan() === null) {
            return back()->with('error', '有効な通所介護計画書がありません。先に計画書を作成してください。');
        }

        [$from, $to] = $this->period();

        return $this->enqueue(LlmFeature::GoalProgress, $resident, $request, $from, $to);
    }

    // ---------------------------------------------------------------

    /**
     * 画面から送られた原文を記録へ足す。
     *
     * 【変換の前に保存する】
     * 原文はAIが何を変えたのかを後から検証するための原本である。
     * 変換に使った文章が残っていなければ、検証のしようがない。
     *
     * 【書き換えずに積む】
     * 同じ内容が続けて送られたときだけ捨てる。押し直しや再変換で同じ文が
     * 二重に積まれるのを防ぐためで、内容が違えば必ず別の1件として残す。
     */
    private function storeRawNote(Request $request, ServiceRecord $record): void
    {
        $rawNote = $request->string('raw_note')->trim()->value();

        if ($rawNote === '') {
            return;
        }

        $record->loadMissing('notes');

        if (trim((string) $record->notes->last()?->body) === $rawNote) {
            return;
        }

        // 上限は記録本文と同じ。音声入力が延々と続いた状態でそのまま
        // 送ると、トークンも費用も跳ね上がる。
        $record->notes()->create([
            'recorded_by' => $request->user()?->id,
            'body' => mb_substr($rawNote, 0, 5000),
            'input_method' => $request->string('input_method')->value() === 'voice'
                ? NoteInputMethod::Voice
                : NoteInputMethod::Keyboard,
        ]);

        $record->unsetRelation('notes');
    }

    /**
     * ジョブの行を作ってキューへ積む。
     *
     * 行の作成と重複の確認は1つのトランザクションで行い、確認した行に
     * ロックを取る。同じボタンが同時に2回押されても、片方だけが通る。
     */
    private function enqueue(
        LlmFeature $feature,
        Model $target,
        Request $request,
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null,
    ): RedirectResponse {
        $job = DB::transaction(function () use ($feature, $target, $request, $from, $to): ?LlmJob {
            $running = LlmJob::query()
                ->forTarget($target)
                ->where('feature', $feature)
                ->blocking()
                ->lockForUpdate()
                ->first();

            if ($running !== null) {
                return null;
            }

            return LlmJob::query()->create([
                'feature' => $feature,
                'target_type' => $target->getMorphClass(),
                'target_id' => $target->getKey(),
                'period_from' => $from,
                'period_to' => $to,
                'status' => LlmJobStatus::Queued,
                'requested_by' => $request->user()?->id,
            ]);
        });

        if ($job === null) {
            return back()->with('error', "{$feature->label()}はすでに実行中です。完了までお待ちください。");
        }

        try {
            Bus::dispatch(new RunLlmFeature($job->id));
        } catch (Throwable $exception) {
            // キューそのものに届かなかった。行を待機中のまま残すと、画面は
            // いつまでも「実行中」を出し続ける。失敗として確定させる。
            $job->markFailed(LlmErrorType::QueueUnavailable->value, $exception);

            Log::error('AI処理をキューへ積めませんでした。', [
                'job_id' => $job->id,
                'feature' => $feature->value,
                'exception' => $exception,
            ]);

            return back()->with('error', LlmErrorType::QueueUnavailable->userMessage());
        }

        return back()->with('success', $feature->acceptedMessage());
    }

    /**
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    private function period(): array
    {
        return [today()->subMonths(self::PERIOD_MONTHS), today()];
    }
}
