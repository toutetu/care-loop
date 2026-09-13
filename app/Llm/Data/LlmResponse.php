<?php

namespace App\Llm\Data;

/**
 * LLMからの応答1件。
 *
 * stopReason を必ず保持する。HTTP 200 で返ってきていても、出力が途中で
 * 切れている（max_tokens）ことや、安全性の判断で拒否されている（refusal）
 * ことがある。本文だけを見て成功と判断してはいけない。
 */
final readonly class LlmResponse
{
    /**
     * @param  array<string, mixed>|null  $parsed  構造化出力として解釈済みの値
     */
    public function __construct(
        public string $text,
        public ?array $parsed,
        public string $model,
        public ?string $stopReason,
        public TokenUsage $usage,
        public int $latencyMs,
    ) {}

    /** 出力が上限に達して途中で切れたか。 */
    public function wasTruncated(): bool
    {
        return $this->stopReason === 'max_tokens';
    }

    /** 安全性の判断で生成が拒否されたか。 */
    public function wasRefused(): bool
    {
        return $this->stopReason === 'refusal';
    }

    /** 構造化出力として値を受け取れたか。 */
    public function hasParsedContent(): bool
    {
        return $this->parsed !== null;
    }
}
