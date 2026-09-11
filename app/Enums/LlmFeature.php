<?php

namespace App\Enums;

/**
 * LLM連携機能の識別子。要件定義の機能IDをそのまま値にしている。
 * 設計書とコードを突き合わせられるようにするため。
 */
enum LlmFeature: string
{
    case GoalProgress = 'F-LLM-01';
    case RiskDetection = 'F-LLM-02';
    case Handover = 'F-LLM-03';
    case FamilyReport = 'F-LLM-04';
    case VoiceTransform = 'F-LLM-05';

    public function label(): string
    {
        return match ($this) {
            self::GoalProgress => '目標進捗要約',
            self::RiskDetection => 'リスク兆候抽出',
            self::Handover => '申し送り生成',
            self::FamilyReport => '家族向け報告',
            self::VoiceTransform => '音声入力の三面変換',
        };
    }

    /**
     * この機能で使うモデル。機能ごとの指定がなければ既定モデルを使う。
     * コストと品質のトレードオフを、コードを変えずに運用で調整できるようにしている。
     */
    public function model(): string
    {
        $configured = config("llm.models.{$this->value}");

        return is_string($configured) && $configured !== ''
            ? $configured
            : (string) config('llm.default_model');
    }

    public function maxTokens(): int
    {
        return (int) (config("llm.max_tokens.{$this->value}") ?? config('llm.max_tokens.default'));
    }
}
