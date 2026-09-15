<?php

namespace App\Jobs;

use App\Support\QueueHeartbeat;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;

/**
 * ワーカーが生きているかを確かめるだけのジョブ。
 *
 * AI処理をキューへ移すと、ワーカーが止まっているときに「押したのに
 * 何も起きない」状態になる。エラーは出ない。だから、コードを切り替える
 * 前と、デプロイのたびに、ワーカーが実際にジョブを拾うことを確かめる
 * 手段が要る（careloop:queue-check）。
 *
 * ワーカー側で応答時刻をキャッシュに書き、積んだ側がそれを読む。
 * ログを目で探すより確実で、コマンドが自分で合否を出せる。
 */
final class QueueHealthCheck implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 10;

    public function __construct(public readonly string $token) {}

    public function handle(): void
    {
        QueueHeartbeat::touch();

        Cache::put(self::key($this->token), now()->toIso8601String(), now()->addMinutes(10));
    }

    public static function respondedAt(string $token): ?CarbonInterface
    {
        $value = Cache::get(self::key($token));

        return is_string($value) && $value !== '' ? Date::parse($value) : null;
    }

    private static function key(string $token): string
    {
        return "queue.health_check.{$token}";
    }
}
