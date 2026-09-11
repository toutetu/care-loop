<?php

namespace App\Enums;

enum RiskSeverity: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    public function label(): string
    {
        return match ($this) {
            self::High => '重要度 高',
            self::Medium => '重要度 中',
            self::Low => '重要度 低',
        };
    }

    /** 一覧を重要度順に並べるための重み。 */
    public function weight(): int
    {
        return match ($this) {
            self::High => 3,
            self::Medium => 2,
            self::Low => 1,
        };
    }

    /** ダッシュボードの「要対応」に出すか。 */
    public function requiresAction(): bool
    {
        return $this === self::High;
    }
}
