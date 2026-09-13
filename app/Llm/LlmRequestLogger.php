<?php

namespace App\Llm;

use App\Enums\LlmErrorType;
use App\Llm\Data\LlmRequest;
use App\Llm\Data\LlmResponse;
use App\Models\LlmJob;
use App\Models\LlmRequest as LlmRequestRecord;

/**
 * LLM呼び出しの監査ログを残す（F-LLM-07）。
 *
 * 成功も失敗も、リトライの1回ごとに1行記録する。集計してはいけない。
 * 「3回目でようやく成功した」ことが見えなくなると、レート制限に当たり続けている
 * といった運用上の異常に気づけなくなるため。
 *
 * 【保存するのはマスキング後の内容だけ】
 * ここへ渡ってくる LlmRequest の本文は、上位のプロンプト組み立て時点で
 * すでに個人情報をプレースホルダへ置換済みである。
 * ログ経由で個人情報が二次流出しないよう、この層では復号も実名化も行わない。
 */
final class LlmRequestLogger
{
    public function success(
        LlmRequest $request,
        LlmResponse $response,
        int $retryCount,
        ?LlmJob $job,
    ): LlmRequestRecord {
        return LlmRequestRecord::create([
            'llm_job_id' => $job?->id,
            'feature' => $request->feature,
            'model' => $request->model,
            'prompt_version' => config('llm.prompt_version'),
            'input_tokens' => $response->usage->inputTokens,
            'output_tokens' => $response->usage->outputTokens,
            'cache_read_input_tokens' => $response->usage->cacheReadInputTokens,
            'cache_creation_input_tokens' => $response->usage->cacheCreationInputTokens,
            'latency_ms' => $response->latencyMs,
            'status' => 'success',
            'retry_count' => $retryCount,
            'stop_reason' => $response->stopReason,
            'estimated_cost_usd' => LlmRequestRecord::calculateCostUsd(
                $request->model,
                $response->usage->inputTokens,
                $response->usage->outputTokens,
            ),
            'masked_request' => $this->payload($request),
            'masked_response' => $response->text,
        ]);
    }

    public function failure(
        LlmRequest $request,
        LlmErrorType $errorType,
        int $retryCount,
        ?LlmJob $job,
        int $latencyMs = 0,
    ): LlmRequestRecord {
        return LlmRequestRecord::create([
            'llm_job_id' => $job?->id,
            'feature' => $request->feature,
            'model' => $request->model,
            'prompt_version' => config('llm.prompt_version'),
            'latency_ms' => $latencyMs,
            'status' => 'failed',
            'error_type' => $errorType->value,
            'retry_count' => $retryCount,
            // 失敗時は出力トークンが発生しないか、発生しても課金対象を
            // 正確に取れない。推測で数値を入れず 0 のままにする。
            'estimated_cost_usd' => 0,
            'masked_request' => $this->payload($request),
        ]);
    }

    /**
     * 送信内容を1つの文字列にまとめる。あとから「何を送ったか」を
     * 追跡できるようにするためで、再送には使わない。
     */
    private function payload(LlmRequest $request): string
    {
        return "[system]\n{$request->systemPrompt}\n\n[user]\n{$request->userMessage}";
    }
}
