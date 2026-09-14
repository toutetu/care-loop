<?php

namespace Tests\Feature;

use App\Enums\LlmErrorType;
use App\Enums\LlmFeature;
use App\Llm\Contracts\LlmClient;
use App\Llm\Data\LlmRequest;
use App\Llm\Exceptions\LlmException;
use App\Llm\LlmGateway;
use App\Models\LlmRequest as LlmRequestRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Mockery;
use Tests\TestCase;

/**
 * 料金表にないモデルを指定したときの挙動。
 *
 * 【なぜこれを守るのか】
 * 単価が分からないと実行後の費用を算出できない。費用が積み上がらなければ、
 * 月次の上限判定も永久に発動しない。課金は実際に発生しているのに、
 * 画面上は無料に見える状態になる。費用管理としては最悪の壊れ方である。
 *
 * 環境変数のモデル名を一文字打ち間違えただけでこの状態になるため、
 * 無言で通さず、その場で止める。
 */
class LlmPricingGuardTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------
    // 費用の算出
    // ---------------------------------------------------------------

    public function test_料金表にあるモデルは費用を算出できる(): void
    {
        // Sonnet: 入力 $2.00 / 出力 $10.00（100万トークンあたり）
        $cost = LlmRequestRecord::calculateCostUsd('claude-sonnet-5', 4_000, 1_500);

        $this->assertSame(0.023, $cost);
    }

    public function test_料金表にないモデルでは費用を0にせず失敗させる(): void
    {
        // 0 を返すと「無料で実行できた」と記録されてしまう。
        // 合計が増えないため、月次上限も発動しなくなる。
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('claude-haiku-4-5-20251001');

        LlmRequestRecord::calculateCostUsd('claude-haiku-4-5-20251001', 4_000, 1_500);
    }

    public function test_日付つきのモデル名は料金表と一致しない(): void
    {
        // 実際に踏みやすい間違い。料金表のキーは日付サフィックスを含まない。
        $this->assertTrue(LlmRequestRecord::hasPricing('claude-haiku-4-5'));
        $this->assertFalse(LlmRequestRecord::hasPricing('claude-haiku-4-5-20251001'));
    }

    // ---------------------------------------------------------------
    // 実行前の遮断
    // ---------------------------------------------------------------

    public function test_単価の分からないモデルではapiを呼ばない(): void
    {
        // 呼んでしまってから「値段が分からない」と気づいても、
        // 課金はすでに発生している。
        $client = Mockery::mock(LlmClient::class);
        $client->shouldNotReceive('send');

        $this->app->instance(LlmClient::class, $client);

        try {
            $this->gateway()->send($this->requestUsing('gpt-4o'), ['type' => 'object']);
            $this->fail('例外が投げられませんでした。');
        } catch (LlmException $exception) {
            $this->assertSame(LlmErrorType::UnknownModel, $exception->errorType);
        }
    }

    public function test_単価不明は再試行しても無駄な失敗として扱う(): void
    {
        // 設定の誤りなので、時間を置いても直らない
        $this->assertFalse(LlmErrorType::UnknownModel->isRetryable());
        $this->assertFalse(LlmErrorType::UnknownModel->isCorrectable());
    }

    public function test_単価不明は運用者への通知が必要な失敗である(): void
    {
        // 放置すると上限が効かないまま課金が続く
        $this->assertTrue(LlmErrorType::UnknownModel->needsOperatorAttention());
    }

    public function test_職員には設定の問題として伝える(): void
    {
        // 職員に料金表の話をしても対処できない
        $this->assertSame(
            'システム設定に問題があります。管理者にご連絡ください。',
            LlmErrorType::UnknownModel->userMessage(),
        );
    }

    // ---------------------------------------------------------------

    private function gateway(): LlmGateway
    {
        return $this->app->make(LlmGateway::class);
    }

    private function requestUsing(string $model): LlmRequest
    {
        return new LlmRequest(
            feature: LlmFeature::RiskDetection,
            model: $model,
            systemPrompt: 'システムプロンプト',
            userMessage: '本文',
            maxTokens: 1000,
        );
    }
}
