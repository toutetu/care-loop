<?php

namespace App\Enums;

/**
 * リスク指摘の検出元（要件定義 7.1節の中核）。
 *
 * 数値で判定できるもの（体重減少率・水分摂取量・バイタルの異常値）は
 * 決定的なロジックで算出する。再現性があり、後から検証できる。
 *
 * 「ふらつきの記述が増えている」のような、読まないと分からない質的変化は
 * LLMが抽出する。こちらは要確認として扱う。
 *
 * 画面ではこの違いをバッジで表示し、職員が信頼度を判断できるようにする。
 * LLMに全部投げる実装との決定的な差がここにある。
 */
enum RiskSource: string
{
    case RuleBased = 'rule_based';
    case LlmDetected = 'llm_detected';
    case Both = 'both';

    public function label(): string
    {
        return match ($this) {
            self::RuleBased => 'ルールベース検出',
            self::LlmDetected => 'AIが記述から検出',
            self::Both => '両方で検出',
        };
    }

    /** 再現性があり、同じ入力なら必ず同じ結果になるか。 */
    public function isDeterministic(): bool
    {
        return $this === self::RuleBased;
    }
}
