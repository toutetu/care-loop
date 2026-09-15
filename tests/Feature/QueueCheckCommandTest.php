<?php

namespace Tests\Feature;

use App\Support\QueueHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * ワーカーの生存確認（careloop:queue-check）。
 *
 * AI処理をキューへ移すと、ワーカーが止まっているときに「押したのに何も
 * 起きない」状態になる。エラーは出ない。だから、コードを切り替える前と
 * デプロイのたびに、ワーカーが実際にジョブを拾うことを確かめる手段が要る。
 */
class QueueCheckCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_ワーカーが応答すれば成功で終わる(): void
    {
        // テストのキューは sync なので、積んだ直後にその場で処理される
        $this->artisan('careloop:queue-check', ['--wait' => 5])
            ->expectsOutputToContain('ワーカーが応答しました')
            ->assertExitCode(0);

        $this->assertNotNull(QueueHeartbeat::lastSeenAt(), '応答したワーカーは稼働時刻を残す');
    }

    public function test_応答がなければ失敗で終わり確認事項を示す(): void
    {
        // 積んだだけで誰も処理しない状態を作る
        Queue::fake();
        Sleep::fake();

        $this->artisan('careloop:queue-check', ['--wait' => 0])
            ->expectsOutputToContain('処理されませんでした')
            ->expectsOutputToContain('Deploy')
            ->assertExitCode(1);
    }
}
