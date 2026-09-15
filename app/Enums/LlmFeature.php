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

    /**
     * 受け付けた直後に画面へ出す文言。
     *
     * 実行はキューで行うため、押した時点では結果がない。「受け付けた」ことと
     * 「待てば画面が変わる」ことの両方を伝えないと、職員はもう一度押す。
     */
    public function acceptedMessage(): string
    {
        return match ($this) {
            self::VoiceTransform => '変換を受け付けました。完了すると3つの文章がこの画面に表示されます。',
            self::RiskDetection => 'リスク兆候の抽出を受け付けました。完了すると結果がこの画面に表示されます。',
            self::GoalProgress => '目標進捗の要約を受け付けました。完了すると結果がこの画面に表示されます。',
            self::Handover, self::FamilyReport => "{$this->label()}を受け付けました。",
        };
    }

    /**
     * 完了したときに画面へ出す文言。次に職員がすべきこと（確認）まで書く。
     */
    public function completedMessage(): string
    {
        return match ($this) {
            self::VoiceTransform => '記録・ご家族向け・申し送りの3つの文章を生成しました。内容をご確認ください。',
            self::RiskDetection => 'リスク兆候を抽出しました。根拠の記録を確認してから対応をご判断ください。',
            self::GoalProgress => '目標進捗の要約を作成しました。モニタリング記録へ転記する前にご確認ください。',
            self::Handover, self::FamilyReport => "{$this->label()}が完了しました。内容をご確認ください。",
        };
    }
}
