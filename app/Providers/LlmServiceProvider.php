<?php

namespace App\Providers;

use Anthropic\Client as AnthropicClient;
use App\Llm\Clients\ClaudeClient;
use App\Llm\Clients\FakeClient;
use App\Llm\Contracts\LlmClient;
use Illuminate\Support\ServiceProvider;

/**
 * LLMクライアントの結線。
 *
 * 【APIキーが無ければ FakeClient へ落とす】
 * driver に claude を指定していても、APIキーが空なら実APIは呼べない。
 * ここで例外を投げて画面を落とすのではなく、固定応答を返す実装に差し替える。
 *
 * こうしている理由は2つある。
 *   1. リポジトリをクローンした人が、課金設定なしでアプリ全体を触れる
 *   2. キーの失効に気づかないまま本番が全面停止する事態を避けられる
 *      （機能は劣化するが、記録の入力・参照は動き続ける）
 *
 * 画面には「デモモードで動作しています」と明示する。
 * AIが動いていないことを利用者に隠さないための表示である。
 */
class LlmServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LlmClient::class, function (): LlmClient {
            $driver = (string) config('llm.driver', 'claude');
            $apiKey = (string) config('llm.api_key', '');

            if ($driver !== 'claude' || $apiKey === '') {
                return new FakeClient;
            }

            return new ClaudeClient(
                new AnthropicClient(apiKey: $apiKey),
                max(1, (int) config('llm.timeout', 60)),
            );
        });
    }
}
