<?php

namespace App\Enums;

/**
 * 口頭連絡タスクの状態（F-20）。
 *
 * 「連絡帳に書いてあるから伝えた」を仕組みで潰すための状態管理。
 * pending のまま日をまたいだタスクは管理者へ通知する。
 */
enum VerbalContactStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => '未連絡',
            self::Completed => '連絡済み',
            self::Cancelled => '取り消し',
        };
    }
}
