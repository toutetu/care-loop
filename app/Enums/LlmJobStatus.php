<?php

namespace App\Enums;

/**
 * LLM実行ジョブの状態。
 *
 * queued -> running -> succeeded / failed
 *    \          \
 *     `----------`--> cancelled（職員が中止した）
 *
 * LLMの応答には数秒から数十秒かかるため、すべての呼び出しを非同期ジョブとして
 * 実行する。フロントエンドはこの状態をポーリングして進捗を表示する。
 *
 * 【中止を失敗と分ける】
 * 中止は職員が自分で選んだ結果であって、システムの不具合ではない。
 * 失敗に混ぜると、失敗の件数と「管理者にご連絡ください」の案内が
 * 押し間違いの数だけ増える。
 */
enum LlmJobStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Queued => '待機中',
            self::Running => '実行中',
            self::Succeeded => '完了',
            self::Failed => '失敗',
            self::Cancelled => '中止',
        };
    }

    public function isFinished(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed, self::Cancelled], true);
    }

    /** 同一対象への重複実行を防ぐため、実行中とみなす状態か。 */
    public function isActive(): bool
    {
        return ! $this->isFinished();
    }
}
