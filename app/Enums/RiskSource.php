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
 *
 * バッジの文言と色は resources/js/lib/care-presentation.ts が持つ。
 * Both は3つ目の色を作らず、RuleBased と LlmDetected のバッジを2枚並べて
 * 表すため、この enum 側に表示用のラベルは置いていない。
 */
enum RiskSource: string
{
    case RuleBased = 'rule_based';
    case LlmDetected = 'llm_detected';
    case Both = 'both';

    /** 再現性があり、同じ入力なら必ず同じ結果になるか。 */
    public function isDeterministic(): bool
    {
        return $this === self::RuleBased;
    }
}
