<?php

namespace App\Llm;

use App\Enums\LlmErrorType;
use App\Llm\Contracts\LlmClient;
use App\Llm\Data\LlmRequest;
use App\Llm\Data\LlmResponse;
use App\Llm\Exceptions\LlmException;
use App\Llm\Support\ResponseValidator;
use App\Models\LlmJob;
use App\Models\LlmRequest as LlmRequestRecord;
use Carbon\CarbonInterface;
use Illuminate\Support\Sleep;

/**
 * LLM呼び出しの司令塔。要件定義 7.4節のエラーハンドリングをここで実装する。
 *
 * 【3種類の失敗を、それぞれ違う方法で扱う】
 *
 *   1. 通信の失敗（429 / 529 / 5xx / タイムアウト）
 *      → 指数バックオフ＋ジッターで再送する。
 *        サーバーが Retry-After を返していればそちらを優先する。
 *        自前の待ち時間より、サーバーが指定した値のほうが正確なため。
 *
 *   2. 要求の失敗（401 / 400 / refusal）
 *      → 再送しない。同じ内容を送り直しても成功する見込みがなく、
 *        コストとレイテンシを浪費するだけになる。
 *
 *   3. 応答の失敗（max_tokens / JSON壊れ / スキーマ不一致）
 *      → 内容を修正して「1回だけ」投げ直す。
 *        これらは HTTP 200 で返ってくるため、通常のHTTPエラー処理では
 *        捕捉できない。LLM連携に固有の失敗として独立して扱う。
 *
 * 【再実行は必ず上限を設ける】
 * 無限リトライはコスト暴走に直結する。修正による再実行は1回限り、
 * 通信の再送は config('llm.max_retries') 回までとする。
 *
 * 【実行前に予算を確認する】
 * 月次上限に達していたら、APIを呼ぶ前に止める。
 * 呼んでから気づいたのでは手遅れになる。
 */
final class LlmGateway
{
    public function __construct(
        private readonly LlmClient $client,
        private readonly ResponseValidator $validator,
        private readonly LlmRequestLogger $logger,
    ) {}

    /**
     * 送信し、検証済みの構造化データを返す。
     *
     * @param  array<string, mixed>  $schema  期待する JSON Schema
     * @return array<string, mixed>
     *
     * @throws LlmException
     */
    public function send(LlmRequest $request, array $schema, ?LlmJob $job = null): array
    {
        // 単価の確認を先に置く。上限判定そのものが、単価が分かることを前提に
        // 成り立っているため。
        $this->assertModelIsPriced($request->model);
        $this->assertWithinBudget();

        $maxRetries = max(0, (int) config('llm.max_retries'));
        $current = $request;
        $networkRetries = 0;
        $correctionUsed = false;

        // 合計時間の締切。再試行と訂正が重なって数分に達すると、その前に
        // 手前のゲートウェイが切り、職員には英語のエラーページだけが出る。
        $deadlineAt = now()->addSeconds(max(1, (int) config('llm.deadline')));

        while (true) {
            try {
                $response = $this->attempt($current, $networkRetries, $job);

                return $this->validator->validate($response, $schema);
            } catch (LlmException $exception) {
                // 締切を過ぎていれば、もう一度送らずにここで終える。
                // 間に合わないと分かっている送信は、費用だけを増やす。
                if (now()->greaterThanOrEqualTo($deadlineAt)) {
                    throw $exception;
                }

                // 1. 時間を置けば直るかもしれない失敗
                if ($exception->isRetryable() && $networkRetries < $maxRetries) {
                    $networkRetries++;
                    $this->waitBeforeRetry($exception, $networkRetries, $deadlineAt);

                    continue;
                }

                // 2. 内容を直せば通るかもしれない失敗（1回限り）
                if ($exception->isCorrectable() && ! $correctionUsed) {
                    $correctionUsed = true;
                    $current = $this->correct($current, $exception);

                    continue;
                }

                throw $exception;
            }
        }
    }

    /**
     * 1回送信し、成功・失敗いずれも監査ログに記録する。
     *
     * 応答が返っても、stop_reason が max_tokens（途中で切れた）や
     * refusal（生成を拒否された）のことがある。本文だけを見て
     * 成功と判断してはいけないため、ここで例外へ変換する。
     */
    private function attempt(LlmRequest $request, int $retryCount, ?LlmJob $job): LlmResponse
    {
        try {
            $response = $this->client->send($request);
        } catch (LlmException $exception) {
            $this->logger->failure($request, $exception->errorType, $retryCount, $job);

            throw $exception;
        }

        $this->logger->success($request, $response, $retryCount, $job);

        if ($response->wasRefused()) {
            throw LlmException::refused('安全性の判断により生成が拒否されました。');
        }

        if ($response->wasTruncated()) {
            throw LlmException::truncated('出力が上限に達したため途中で終了しました。');
        }

        return $response;
    }

    /**
     * 失敗の内容に応じてリクエストを作り直す。
     *
     * 途中で切れたときは上限を引き上げ、形式が違うときは何が問題だったかを
     * 添える。修正指示は userMessage 側に足す。systemPrompt を変えると
     * プロンプトキャッシュが無効になり、再実行のコストが跳ね上がるため。
     */
    private function correct(LlmRequest $request, LlmException $exception): LlmRequest
    {
        if ($exception->errorType === LlmErrorType::Truncated) {
            $ceiling = (int) config('llm.max_tokens.default') * 4;

            return $request->withMaxTokens(min($request->maxTokens * 2, $ceiling));
        }

        return $request->withCorrection(
            $exception->getMessage()."\n上記を修正し、指定された形式のJSONのみを出力してください。"
        );
    }

    /**
     * 再送までの待ち時間。
     *
     * Retry-After があればそれに従う。無ければ指数バックオフ
     * （1秒 → 2秒 → 4秒）にジッターを加える。
     * ジッターを入れるのは、同時に失敗した複数のジョブが
     * まったく同じ時刻に再送して再び詰まるのを避けるため。
     *
     * ただし待つ時間には上限を置く。Anthropic の Retry-After は60秒以上に
     * なることがあり、リクエストの中でそれだけ眠ると、待っているあいだに
     * 手前のゲートウェイが切る。待った意味がなくなるうえ、職員には英語の
     * エラーページだけが残る。締切までの残り時間も超えない。
     */
    private function waitBeforeRetry(LlmException $exception, int $attempt, CarbonInterface $deadlineAt): void
    {
        $delayMs = $exception->retryAfterSeconds !== null
            ? $exception->retryAfterSeconds * 1000
            : (int) config('llm.retry_base_delay_ms') * (2 ** ($attempt - 1)) + random_int(0, 250);

        $capMs = max(0, (int) config('llm.max_retry_wait')) * 1000;
        $remainingMs = max(0, now()->diffInMilliseconds($deadlineAt, absolute: false));

        $waitMs = (int) min($delayMs, $capMs, $remainingMs);

        if ($waitMs <= 0) {
            return;
        }

        Sleep::for($waitMs)->milliseconds();
    }

    /**
     * 単価の分かるモデルかを、APIを呼ぶ前に確認する。
     *
     * 【なぜ実行を止めるのか】
     * 料金表にないモデルを指定すると、実行後に費用を算出できない。
     * 費用が積み上がらないため、月次の上限判定も永久に発動しなくなる。
     * 課金は実際に発生しているのに、画面上は無料に見える状態になる。
     *
     * 設定の誤りは、無言で通すよりその場で止めたほうが被害が小さい。
     * 環境変数のモデル名を一文字打ち間違えただけで、上限が効かなくなる。
     */
    private function assertModelIsPriced(string $model): void
    {
        if (LlmRequestRecord::hasPricing($model)) {
            return;
        }

        throw new LlmException(
            LlmErrorType::UnknownModel,
            "モデル {$model} の単価が config/llm.php の pricing にありません。"
            .'単価が分からないと月次上限の判定が効かないため、実行を中止しました。',
        );
    }

    /**
     * 月次の利用上限を超えていないかを、APIを呼ぶ前に確認する。
     */
    private function assertWithinBudget(): void
    {
        $budget = (float) config('llm.monthly_budget_usd');

        if ($budget <= 0) {
            return;
        }

        $spent = LlmRequestRecord::monthlySpendUsd();

        if ($spent >= $budget) {
            throw LlmException::budgetExceeded($spent, $budget);
        }
    }
}
