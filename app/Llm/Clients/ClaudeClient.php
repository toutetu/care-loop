<?php

namespace App\Llm\Clients;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\APITimeoutException;
use Anthropic\Messages\Message;
use Anthropic\Messages\TextBlock;
use App\Enums\LlmErrorType;
use App\Llm\Contracts\LlmClient;
use App\Llm\Data\LlmRequest;
use App\Llm\Data\LlmResponse;
use App\Llm\Data\TokenUsage;
use App\Llm\Exceptions\LlmException;
use App\Llm\Support\JsonSchema;

/**
 * Anthropic の Claude API を呼び出す実装。
 *
 * 【この層の責務は「1回送って結果を返す」ことだけ】
 * リトライもバックオフもここには書かない。上位の LlmGateway が持つ。
 * 再送の判断をクライアント内に埋めてしまうと、FakeClient でその挙動を
 * 検証できなくなり、エラーハンドリングのテストが成立しなくなるため。
 *
 * 【SDKの例外をアプリの語彙へ翻訳する】
 * SDK は APITimeoutException / RateLimitException / BadRequestException など
 * 細かく例外を分けている。これをそのまま上位へ流すと、アプリのあちこちが
 * SDKの型に依存してしまう。ここで LlmErrorType へ翻訳し、
 * 上位は「リトライしてよいか」だけを見れば済むようにする。
 *
 * 【プロンプトキャッシュ】
 * システムプロンプトに cacheControl を付けている。毎回同じ内容（役割定義・
 * 介護ドメインの用語・出力スキーマの説明）をキャッシュ対象にすることで、
 * 入力トークンの課金を減らす。効いているかは usage の
 * cacheReadInputTokens を記録して確認する。
 */
final class ClaudeClient implements LlmClient
{
    public function __construct(
        private readonly Client $client,
        private readonly int $timeoutSeconds,
    ) {}

    public function send(LlmRequest $request): LlmResponse
    {
        $startedAt = hrtime(true);

        try {
            $message = $this->client->messages->create(
                maxTokens: $request->maxTokens,
                messages: [['role' => 'user', 'content' => $request->userMessage]],
                model: $request->model,
                outputConfig: $this->outputConfig($request),
                system: [[
                    'type' => 'text',
                    'text' => $request->systemPrompt,
                    'cacheControl' => ['type' => 'ephemeral'],
                ]],
                // 締切が近いときは、ゲートウェイが送信ごとに短い上限を指定してくる
                requestOptions: ['timeout' => $request->timeoutSeconds ?? $this->timeoutSeconds],
            );
        } catch (APITimeoutException $e) {
            throw LlmException::timeout('応答が時間内に返りませんでした。', $e);
        } catch (APIConnectionException $e) {
            throw new LlmException(LlmErrorType::Timeout, 'APIへの接続に失敗しました。', previous: $e);
        } catch (APIStatusException $e) {
            throw $this->translate($e);
        }

        return $this->toResponse(
            $message,
            (int) round((hrtime(true) - $startedAt) / 1_000_000),
        );
    }

    public function name(): string
    {
        return 'claude';
    }

    /**
     * 構造化出力の指定。
     *
     * スキーマを渡してモデル側に形を守らせる。ただしこれだけに頼らず、
     * 受け取った値は上位で必ず検証する。スキーマに合っていても、
     * 根拠のない内容が入っていることはあるため（多層の検証）。
     *
     * JsonSchema::forApi() を通すのは、object ノードへの
     * additionalProperties: false の付与を書き忘れても 400 にならないようにするため。
     *
     * @return array<string, mixed>|null
     */
    private function outputConfig(LlmRequest $request): ?array
    {
        if ($request->jsonSchema === null) {
            return null;
        }

        return [
            'format' => [
                'type' => 'json_schema',
                'schema' => JsonSchema::forApi($request->jsonSchema),
            ],
        ];
    }

    private function toResponse(Message $message, int $latencyMs): LlmResponse
    {
        $text = '';
        $parsed = null;

        // content は複数ブロックの配列。text 以外のブロックが先頭に来ることが
        // あるため、型を確認せずに content[0]->text を読んではいけない。
        foreach ($message->content as $block) {
            if ($block instanceof TextBlock) {
                $text .= $block->text;

                if ($parsed === null && is_array($block->parsed)) {
                    /** @var array<string, mixed> $blockParsed */
                    $blockParsed = $block->parsed;
                    $parsed = $blockParsed;
                }
            }
        }

        return new LlmResponse(
            text: $text,
            parsed: $parsed,
            model: $message->model,
            stopReason: $message->stopReason,
            usage: new TokenUsage(
                inputTokens: $message->usage->inputTokens,
                outputTokens: $message->usage->outputTokens,
                cacheReadInputTokens: $message->usage->cacheReadInputTokens ?? 0,
                cacheCreationInputTokens: $message->usage->cacheCreationInputTokens ?? 0,
            ),
            latencyMs: $latencyMs,
        );
    }

    /**
     * SDKのHTTPエラーをアプリの失敗分類へ翻訳する。
     *
     * ステータスコードから種別を決め、判定できないものはサーバーエラー扱いにする。
     * 「分からないものはリトライしてみる」側に倒すのは、
     * 一時的な障害を恒久的な失敗として扱うほうが利用者への影響が大きいため。
     */
    private function translate(APIStatusException $exception): LlmException
    {
        $status = $exception->status ?? 500;
        $type = LlmErrorType::fromStatusCode($status) ?? LlmErrorType::ServerError;

        $detail = $exception->type->value ?? 'unknown';
        $message = sprintf('Claude API がステータス %d を返しました（%s）。', $status, $detail);

        if ($type === LlmErrorType::RateLimit) {
            return LlmException::rateLimited($message, previous: $exception);
        }

        return new LlmException($type, $message, previous: $exception);
    }
}
