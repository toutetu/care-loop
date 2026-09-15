<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;

/**
 * キューワーカーが最後に動いた時刻。
 *
 * 【なぜ要るのか】
 * ワーカーが止まっていても、アプリは何も知らせてくれない。ジョブは
 * 待機中のまま積み上がり、エラーも出ない。「AI処理の実行状況」の画面に
 * 最終稼働時刻を出しておけば、止まっていることに人が気づける。
 *
 * ジョブが動くたびに更新する。キャッシュに置くのは、ワーカーと
 * Webサーバーが別のプロセス（本番では別のマシン）で動くため。
 * データベースのキャッシュ表を使っているので両者から見える。
 */
final class QueueHeartbeat
{
    private const KEY = 'queue.worker.last_seen_at';

    public static function touch(): void
    {
        Cache::forever(self::KEY, now()->toIso8601String());
    }

    public static function lastSeenAt(): ?CarbonInterface
    {
        $value = Cache::get(self::KEY);

        return is_string($value) && $value !== '' ? Date::parse($value) : null;
    }

    public static function forget(): void
    {
        Cache::forget(self::KEY);
    }
}
