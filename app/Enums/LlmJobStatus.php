<?php

namespace App\Enums;

/**
 * LLM実行ジョブの状態。
 *
 * queued -> running -> succeeded / failed
 *
 * LLMの応答には数秒から数十秒かかるため、すべての呼び出しを非同期ジョブとして
 * 実行する。フロントエンドはこの状態をポーリングして進捗を表示する。
 */
enum LlmJobStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Queued => '待機中',
            self::Running => '実行中',
            self::Succeeded => '完了',
            self::Failed => '失敗',
        };
    }

    public function isFinished(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed], true);
    }

    /** 同一対象への重複実行を防ぐため、実行中とみなす状態か。 */
    public function isActive(): bool
    {
        return ! $this->isFinished();
    }
}
