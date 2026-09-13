<?php

namespace Tests\Feature;

use App\Enums\LlmErrorType;
use App\Enums\LlmFeature;
use App\Llm\Clients\FakeClient;
use App\Llm\Data\LlmRequest;
use App\Llm\Exceptions\LlmException;
use App\Llm\LlmGateway;
use App\Llm\LlmRequestLogger;
use App\Llm\Support\ResponseValidator;
use App\Models\LlmRequest as LlmRequestRecord;
use Carbon\CarbonInterval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * 外部API連携のエラーハンドリングを検証する（要件定義 7.4節）。
 *
 * 【実APIでは検証できない】
 * レート制限も認証エラーも壊れたJSONも、実際のAPIに意図的に起こさせることは
 * できない。FakeClient に差し替えられる設計にしてあるからこそ、
 * これらの経路を自動テストで固定できる。
 *
 * 【何を確かめているか】
 * 「リトライする／しない」の切り分けが正しいこと。
 * 認証エラーを再送しても成功する見込みはなく、コストとレイテンシを
 * 浪費するだけになる。呼び出し回数を数えることでそれを保証している。
 */
class LlmGatewayTest extends TestCase
{
    use RefreshDatabase;

    private FakeClient $fake;

    private LlmGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        // 実際に待たせない。バックオフの秒数は assertSlept で確認する。
        Sleep::fake();

        $this->fake = new FakeClient;
        $this->gateway = new LlmGateway($this->fake, new ResponseValidator, new LlmRequestLogger);
    }

    // ---------------------------------------------------------------
    // 正常系
    // ---------------------------------------------------------------

    public function test_検証済みの構造化データが返る(): void
    {
        $result = $this->gateway->send($this->request(), $this->schema());

        $this->assertArrayHasKey('record_text', $result);
        $this->assertSame(1, $this->fake->callCount());
    }

    public function test_成功するとトークン数とコストが監査ログに記録される(): void
    {
        $this->gateway->send($this->request(), $this->schema());

        $log = LlmRequestRecord::sole();

        $this->assertSame('success', $log->status);
        $this->assertSame(LlmFeature::VoiceTransform, $log->feature);
        $this->assertSame(1200, $log->input_tokens);
        $this->assertSame(2800, $log->cache_read_input_tokens);
        $this->assertGreaterThan(0, $log->estimated_cost_usd);
    }

    public function test_監査ログにはマスキング後の内容だけが残る(): void
    {
        $this->gateway->send($this->request(), $this->schema());

        $log = LlmRequestRecord::sole();

        $this->assertStringContainsString('{{RESIDENT_1}}', (string) $log->masked_request);
        $this->assertStringNotContainsString('佐藤', (string) $log->masked_request);
    }

    // ---------------------------------------------------------------
    // リトライしてよい失敗
    // ---------------------------------------------------------------

    public function test_レート制限は待ってから再送し最終的に成功する(): void
    {
        $this->fake->queueFailure(LlmErrorType::RateLimit);

        $result = $this->gateway->send($this->request(), $this->schema());

        $this->assertArrayHasKey('record_text', $result);
        $this->assertSame(2, $this->fake->callCount(), '1回失敗して1回再送するはず');
        Sleep::assertSleptTimes(1);
    }

    public function test_retry_afterが指定されていればその秒数だけ待つ(): void
    {
        // 自前の指数バックオフより、サーバーが指定した待ち時間を優先する
        $this->fake->queueFailure(LlmErrorType::RateLimit, retryAfterSeconds: 7);

        $this->gateway->send($this->request(), $this->schema());

        Sleep::assertSlept(fn (CarbonInterval $duration): bool => (int) $duration->totalSeconds === 7);
    }

    public function test_再送の上限を超えたら諦めて例外になる(): void
    {
        config(['llm.max_retries' => 2]);

        $this->fake
            ->queueFailure(LlmErrorType::Overloaded)
            ->queueFailure(LlmErrorType::Overloaded)
            ->queueFailure(LlmErrorType::Overloaded);

        try {
            $this->gateway->send($this->request(), $this->schema());
            $this->fail('例外が投げられるべき');
        } catch (LlmException $e) {
            $this->assertSame(LlmErrorType::Overloaded, $e->errorType);
        }

        $this->assertSame(3, $this->fake->callCount(), '初回＋再送2回で打ち切る');
    }

    public function test_失敗も監査ログに記録される(): void
    {
        config(['llm.max_retries' => 1]);

        $this->fake
            ->queueFailure(LlmErrorType::ServerError)
            ->queueFailure(LlmErrorType::ServerError);

        try {
            $this->gateway->send($this->request(), $this->schema());
        } catch (LlmException) {
            // 期待どおり
        }

        $logs = LlmRequestRecord::all();

        $this->assertCount(2, $logs, 'リトライの1回ごとに1行残す');
        $this->assertSame(['failed', 'failed'], $logs->pluck('status')->all());
        $this->assertSame([0, 1], $logs->pluck('retry_count')->all());
    }

    // ---------------------------------------------------------------
    // リトライしてはいけない失敗
    // ---------------------------------------------------------------

    public function test_認証エラーは再送しない(): void
    {
        $this->fake->queueFailure(LlmErrorType::Authentication);

        $this->expectException(LlmException::class);

        try {
            $this->gateway->send($this->request(), $this->schema());
        } finally {
            $this->assertSame(1, $this->fake->callCount(), '同じキーで送り直しても成功しない');
            Sleep::assertNeverSlept();
        }
    }

    public function test_リクエスト不正は再送しない(): void
    {
        $this->fake->queueFailure(LlmErrorType::InvalidRequest);

        try {
            $this->gateway->send($this->request(), $this->schema());
            $this->fail('例外が投げられるべき');
        } catch (LlmException $e) {
            $this->assertFalse($e->isRetryable());
        }

        $this->assertSame(1, $this->fake->callCount());
    }

    public function test_生成拒否は再送しない(): void
    {
        $this->fake->queueRawText('{}', stopReason: 'refusal');

        try {
            $this->gateway->send($this->request(), $this->schema());
            $this->fail('例外が投げられるべき');
        } catch (LlmException $e) {
            $this->assertSame(LlmErrorType::Refusal, $e->errorType);
        }

        $this->assertSame(1, $this->fake->callCount());
    }

    // ---------------------------------------------------------------
    // HTTP 200 で返ってくる失敗（LLM連携に固有）
    // ---------------------------------------------------------------

    public function test_壊れたjsonは修正を依頼して1回だけ再実行する(): void
    {
        $this->fake
            ->queueRawText('{"record_text": "途中で切れ')
            ->queueDefaultFor(LlmFeature::VoiceTransform);

        $result = $this->gateway->send($this->request(), $this->schema());

        $this->assertArrayHasKey('record_text', $result);
        $this->assertSame(2, $this->fake->callCount());
    }

    public function test_スキーマ不一致は何が足りないかを添えて再実行する(): void
    {
        // requires_verbal_contact が欠けた応答
        $this->fake
            ->queueParsed(['record_text' => '記録本文のみ'])
            ->queueDefaultFor(LlmFeature::VoiceTransform);

        $result = $this->gateway->send($this->request(), $this->schema());

        $this->assertArrayHasKey('requires_verbal_contact', $result);

        $retry = $this->fake->received()[1];
        $this->assertStringContainsString('requires_verbal_contact', $retry->userMessage);
        $this->assertStringContainsString('修正依頼', $retry->userMessage);
    }

    public function test_修正指示はシステムプロンプトを変更しない(): void
    {
        // システムプロンプトを書き換えるとプロンプトキャッシュが無効になり、
        // 再実行のたびに入力トークンを満額払うことになる
        $this->fake
            ->queueParsed(['record_text' => '不完全'])
            ->queueDefaultFor(LlmFeature::VoiceTransform);

        $this->gateway->send($this->request(), $this->schema());

        [$first, $second] = $this->fake->received();

        $this->assertSame($first->systemPrompt, $second->systemPrompt);
        $this->assertNotSame($first->userMessage, $second->userMessage);
    }

    public function test_修正しても直らなければ諦める(): void
    {
        $this->fake
            ->queueParsed(['record_text' => '不完全'])
            ->queueParsed(['record_text' => 'やはり不完全']);

        try {
            $this->gateway->send($this->request(), $this->schema());
            $this->fail('例外が投げられるべき');
        } catch (LlmException $e) {
            $this->assertSame(LlmErrorType::SchemaMismatch, $e->errorType);
        }

        $this->assertSame(2, $this->fake->callCount(), '修正による再実行は1回限り');
    }

    public function test_出力の途中切れは上限を引き上げて再実行する(): void
    {
        $this->fake
            ->queueRawText('{"record_text": "途中', stopReason: 'max_tokens')
            ->queueDefaultFor(LlmFeature::VoiceTransform);

        $this->gateway->send($this->request(), $this->schema());

        [$first, $second] = $this->fake->received();

        $this->assertSame(4000, $first->maxTokens);
        $this->assertSame(8000, $second->maxTokens);
    }

    // ---------------------------------------------------------------
    // 実行前に止める
    // ---------------------------------------------------------------

    public function test_月次予算を超えていたらapiを呼ばずに止まる(): void
    {
        config(['llm.monthly_budget_usd' => 1.0]);
        LlmRequestRecord::factory()->create(['estimated_cost_usd' => 1.5]);

        try {
            $this->gateway->send($this->request(), $this->schema());
            $this->fail('例外が投げられるべき');
        } catch (LlmException $e) {
            $this->assertSame(LlmErrorType::BudgetExceeded, $e->errorType);
        }

        $this->assertSame(0, $this->fake->callCount(), '呼んでから気づいたのでは手遅れ');
    }

    public function test_エラー種別ごとに画面向けの説明が用意されている(): void
    {
        foreach (LlmErrorType::cases() as $type) {
            $this->assertNotSame('', $type->userMessage(), "{$type->value} の説明文がない");
            $this->assertNotSame('', $type->label());
        }
    }

    // ---------------------------------------------------------------

    private function request(): LlmRequest
    {
        return new LlmRequest(
            feature: LlmFeature::VoiceTransform,
            model: 'claude-opus-5',
            systemPrompt: 'あなたは通所介護の記録を整形するアシスタントです。',
            userMessage: '{{RESIDENT_1}} 様の記録：入浴時にふらつきが見られた。',
            maxTokens: 4000,
            jsonSchema: $this->schema(),
        );
    }

    /** @return array<string, mixed> */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'required' => ['record_text', 'requires_verbal_contact'],
            'properties' => [
                'record_text' => ['type' => 'string'],
                'requires_verbal_contact' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['topic', 'urgency'],
                        'properties' => [
                            'topic' => ['type' => 'string'],
                            'urgency' => ['type' => 'string', 'enum' => ['same_day', 'next_visit']],
                        ],
                    ],
                ],
            ],
        ];
    }
}
