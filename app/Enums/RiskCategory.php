<?php

namespace App\Enums;

/**
 * リスクの種別。通所介護の現場で実際に問題になる事象に絞っている。
 *
 * LLMにはこの選択肢のみを許可する。自由記述でカテゴリを作らせると
 * 表記ゆれが起きて集計できず、画面での絞り込みもできなくなるため。
 */
enum RiskCategory: string
{
    case Fall = 'fall';
    case Aspiration = 'aspiration';
    case Dehydration = 'dehydration';
    case Malnutrition = 'malnutrition';
    case PressureUlcer = 'pressure_ulcer';
    case Infection = 'infection';
    case Medication = 'medication';
    case Skin = 'skin';
    case Bpsd = 'bpsd';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Fall => '転倒',
            self::Aspiration => '誤嚥',
            self::Dehydration => '脱水',
            self::Malnutrition => '低栄養',
            self::PressureUlcer => '褥瘡',
            self::Infection => '感染症',
            self::Medication => '服薬',
            self::Skin => '皮膚トラブル',
            self::Bpsd => '認知症の行動・心理症状',
            self::Other => 'その他',
        };
    }

    /**
     * JSONスキーマに埋め込むための値の一覧。
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
