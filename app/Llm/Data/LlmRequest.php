<?php

namespace App\Llm\Data;

use App\Enums\LlmFeature;

/**
 * LLMへ1回送信する内容。
 *
 * 【systemPrompt と userMessage を分けている理由】
 * システムプロンプト（役割定義・介護ドメインの用語・出力スキーマの説明）は
 * 毎回同じ内容になる。ここをプロンプトキャッシュの対象にすることで、
 * 入力トークンの課金を大きく減らせる。
 * 逆に、毎回変わる内容（対象期間や記録本文）を systemPrompt に混ぜると
 * キャッシュが一切効かなくなる。この境界がコスト設計そのものになる。
 */
final readonly class LlmRequest
{
    /**
     * @param  array<string, mixed>|null  $jsonSchema  構造化出力に使う JSON Schema
     * @param  int|null  $timeoutSeconds  この送信だけの応答待ち上限。null なら設定値
     */
    public function __construct(
        public LlmFeature $feature,
        public string $model,
        public string $systemPrompt,
        public string $userMessage,
        public int $maxTokens,
        public ?array $jsonSchema = null,
        public ?int $timeoutSeconds = null,
    ) {}

    /**
     * 出力が途中で切れたときに、上限を引き上げて投げ直すために使う。
     */
    public function withMaxTokens(int $maxTokens): self
    {
        return new self(
            $this->feature,
            $this->model,
            $this->systemPrompt,
            $this->userMessage,
            $maxTokens,
            $this->jsonSchema,
            $this->timeoutSeconds,
        );
    }

    /**
     * JSONが壊れていた・スキーマに合わなかったときに、
     * 何が問題だったかを添えて投げ直すために使う。
     *
     * systemPrompt は変えない。変えるとキャッシュが無効になるため、
     * 修正指示は必ず userMessage 側に足す。
     */
    public function withCorrection(string $instruction): self
    {
        return new self(
            $this->feature,
            $this->model,
            $this->systemPrompt,
            $this->userMessage."\n\n---\n【修正依頼】\n".$instruction,
            $this->maxTokens,
            $this->jsonSchema,
            $this->timeoutSeconds,
        );
    }

    /**
     * 応答待ちの上限を、締切までの残り時間に合わせて縮めるために使う。
     */
    public function withTimeout(int $timeoutSeconds): self
    {
        return new self(
            $this->feature,
            $this->model,
            $this->systemPrompt,
            $this->userMessage,
            $this->maxTokens,
            $this->jsonSchema,
            max(1, $timeoutSeconds),
        );
    }
}
