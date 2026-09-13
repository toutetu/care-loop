<?php

namespace App\Llm\Data;

use App\Enums\RiskCategory;
use App\Enums\RiskSeverity;
use App\Enums\RiskSource;

/**
 * ルールベースで算出したリスク指標。
 *
 * LLMを通さず、記録の数値から決定的に導いた指摘である。
 * 同じ入力なら必ず同じ結果になり、根拠の数値も添えられる。
 *
 * LLMが抽出する質的な兆候（RiskSource::LlmDetected）とは扱いが違う。
 * こちらは再現性があるため、画面でも「ルールベース検出」として
 * 区別して表示する（要件定義 7.1節）。
 */
final readonly class RiskIndicator
{
    /**
     * @param  list<array{record_id: int|null, date: string, excerpt: string}>  $evidence
     * @param  list<string>  $suggestedActions
     */
    public function __construct(
        public RiskCategory $category,
        public RiskSeverity $severity,
        public string $title,
        public string $reason,
        public array $evidence,
        public array $suggestedActions,
    ) {}

    /**
     * LLMへ渡す際の要約。
     *
     * 「この指標はすでに算出済みなので、重複する指摘は不要」と伝えるために使う。
     * 同じことを二度指摘させても価値がなく、トークンの無駄になる。
     */
    public function summaryLine(): string
    {
        return sprintf('- [%s] %s（%s）', $this->category->label(), $this->title, $this->severity->label());
    }

    /**
     * risk_findings へ保存する形。
     *
     * @return array<string, mixed>
     */
    public function toFindingAttributes(RiskSource $source = RiskSource::RuleBased): array
    {
        return [
            'category' => $this->category,
            'severity' => $this->severity,
            'source' => $source,
            'title' => $this->title,
            'reason' => $this->reason,
            'evidence' => $this->evidence,
            'suggested_actions' => $this->suggestedActions,
        ];
    }
}
