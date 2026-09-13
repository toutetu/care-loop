<?php

namespace App\Llm\Data;

/**
 * 1回のAPI呼び出しで消費したトークン。
 *
 * cacheReadInputTokens を必ず記録する。これが常に 0 のまま推移するときは、
 * システムプロンプトに毎回変わる値（日時など）が混ざっていて
 * プロンプトキャッシュが効いていない可能性が高い。
 * 「キャッシュを使っているつもり」を検知できるようにするための項目である。
 */
final readonly class TokenUsage
{
    public function __construct(
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $cacheReadInputTokens = 0,
        public int $cacheCreationInputTokens = 0,
    ) {}

    /** 課金対象の入力トークン合計（キャッシュ読み取り分を含む）。 */
    public function totalInputTokens(): int
    {
        return $this->inputTokens + $this->cacheReadInputTokens + $this->cacheCreationInputTokens;
    }

    public function cacheHitRate(): float
    {
        $total = $this->totalInputTokens();

        return $total > 0 ? round($this->cacheReadInputTokens / $total, 4) : 0.0;
    }

    /** @return array<string, int> */
    public function toArray(): array
    {
        return [
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'cache_read_input_tokens' => $this->cacheReadInputTokens,
            'cache_creation_input_tokens' => $this->cacheCreationInputTokens,
        ];
    }
}
