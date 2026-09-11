<?php

namespace App\Enums;

/**
 * 短期目標の進捗評価（F-LLM-01）。
 *
 * 【InsufficientData を選択肢に含める理由】
 * 記録が不足している期間に、無理やり「改善」「横ばい」と評価させないため。
 * 「判断できる材料が不足している」と言わせることが、事実に基づかない出力を防ぐ
 * 最も効果的な手段である（要件定義 7.3節）。
 *
 * 選択肢からこれを外すと、LLMは必ずどれかを選ばざるを得なくなり、
 * 根拠のない評価が混ざり始める。
 */
enum ProgressStatus: string
{
    case Improving = 'improving';
    case Unchanged = 'unchanged';
    case Declining = 'declining';
    case InsufficientData = 'insufficient_data';

    public function label(): string
    {
        return match ($this) {
            self::Improving => '改善傾向',
            self::Unchanged => '横ばい',
            self::Declining => '低下傾向',
            self::InsufficientData => '判断できる材料が不足',
        };
    }

    /** 職員の確認を促すべき状態か。 */
    public function needsAttention(): bool
    {
        return in_array($this, [self::Declining, self::InsufficientData], true);
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
