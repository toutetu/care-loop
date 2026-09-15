<?php

namespace App\Console\Commands;

use App\Jobs\QueueHealthCheck;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Throwable;

/**
 * キューワーカーが生きているかを確かめる。
 *
 * 【なぜ tinker の一行ではだめなのか】
 * dispatch してログを目で探す方法は、出るはずのログが出ないときに
 * 「まだ出ていないだけ」なのか「動いていない」のかを判断できない。
 * このコマンドは自分で待って、合否を出す。
 *
 * 【いつ使うか】
 *   - AI処理をキュー実行へ切り替える前（ワーカーが無いと全機能が沈黙する）
 *   - Laravel Cloud で Deploy したあと
 *   - 「AI処理の実行状況」で待機中が増え続けているとき
 *
 * 本番では Laravel Cloud の Commands から実行する。
 */
class QueueCheckCommand extends Command
{
    protected $signature = 'careloop:queue-check
                            {--wait=30 : ワーカーの応答を待つ秒数}';

    protected $description = 'キューワーカーが動いているかを、確認用のジョブを積んで確かめます';

    public function handle(): int
    {
        $connection = (string) config('queue.default');
        $wait = max(0, (int) $this->option('wait'));

        $this->line('');
        $this->line("  キュー接続 : <options=bold>{$connection}</>");

        if ($connection === 'sync') {
            $this->warn('  sync はこのプロセスの中で実行するため、ワーカーの確認にはなりません。');
        }

        $token = Str::uuid()->toString();
        $dispatchedAt = now();

        try {
            Bus::dispatch(new QueueHealthCheck($token));
        } catch (Throwable $exception) {
            $this->error('  キューへ積めませんでした: '.$exception->getMessage());
            $this->line('');

            return self::FAILURE;
        }

        $this->line('  確認用のジョブを積みました。ワーカーの応答を待ちます…');

        $waitUntil = $dispatchedAt->addSeconds($wait);

        while (true) {
            $respondedAt = QueueHealthCheck::respondedAt($token);

            if ($respondedAt !== null) {
                $elapsed = max(0, $dispatchedAt->diffInMilliseconds($respondedAt, absolute: false)) / 1000;

                $this->info(sprintf('  ワーカーが応答しました（%.1f 秒）', $elapsed));
                $this->line('');

                return self::SUCCESS;
            }

            if (now()->greaterThanOrEqualTo($waitUntil)) {
                break;
            }

            Sleep::for(500)->milliseconds();
        }

        $this->error("  {$wait} 秒待ちましたが、ジョブが処理されませんでした。");
        $this->line('  ワーカーが動いていない可能性があります。次を確認してください。');
        $this->line('   - Laravel Cloud で Deploy を押したか（押すまで QUEUE_CONNECTION=cloud は効きません）');
        $this->line('   - マネージドキューのワーカーが増えているか（Monitoring → Queues）');
        $this->line('   - ローカルなら、別の端末で php artisan queue:work を起動しているか');
        $this->line('');

        return self::FAILURE;
    }
}
