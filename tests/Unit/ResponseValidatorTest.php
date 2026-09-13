<?php

namespace Tests\Unit;

use App\Enums\LlmErrorType;
use App\Llm\Data\LlmResponse;
use App\Llm\Data\TokenUsage;
use App\Llm\Exceptions\LlmException;
use App\Llm\Support\JsonSchema;
use App\Llm\Support\ResponseValidator;
use PHPUnit\Framework\TestCase;

/**
 * 応答の取り出しとスキーマ検証。
 *
 * 実機での確認により、次の2点が分かっている。
 *   - Claude API の構造化出力は、object 型すべてに additionalProperties: false が必須
 *   - SDK の TextBlock::$parsed は populate されず、JSONは本文として返る
 * どちらもここで扱う。
 */
class ResponseValidatorTest extends TestCase
{
    private ResponseValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new ResponseValidator;
    }

    // ---------------------------------------------------------------
    // スキーマの正規化
    // ---------------------------------------------------------------

    public function test_入れ子のobjectすべてにadditional_propertiesが付与される(): void
    {
        // 付け忘れると API が 400 を返す。書き手に覚えさせず機械的に補う。
        $normalized = JsonSchema::forApi([
            'type' => 'object',
            'properties' => [
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'detail' => ['type' => 'object', 'properties' => []],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertFalse($normalized['additionalProperties']);
        $this->assertFalse($normalized['properties']['items']['items']['additionalProperties']);
        $this->assertFalse($normalized['properties']['items']['items']['properties']['detail']['additionalProperties']);
    }

    public function test_array型にはadditional_propertiesを付けない(): void
    {
        $normalized = JsonSchema::forApi(['type' => 'array', 'items' => ['type' => 'string']]);

        $this->assertArrayNotHasKey('additionalProperties', $normalized);
    }

    public function test_union型でobjectを含む場合も付与される(): void
    {
        $normalized = JsonSchema::forApi(['type' => ['object', 'null'], 'properties' => []]);

        $this->assertFalse($normalized['additionalProperties']);
    }

    // ---------------------------------------------------------------
    // 本文からの取り出し
    // ---------------------------------------------------------------

    public function test_構造化出力が空でも本文のjsonから取り出せる(): void
    {
        // SDK の parsed は populate されないため、この経路が本番で使われる
        $result = $this->validator->validate(
            $this->response('{"record_text":"入浴介助を実施した。"}'),
            ['type' => 'object', 'required' => ['record_text'], 'properties' => ['record_text' => ['type' => 'string']]],
        );

        $this->assertSame('入浴介助を実施した。', $result['record_text']);
    }

    public function test_コードブロックで囲まれていても取り出せる(): void
    {
        $result = $this->validator->validate(
            $this->response("```json\n{\"record_text\":\"整形済み\"}\n```"),
            ['type' => 'object', 'properties' => ['record_text' => ['type' => 'string']]],
        );

        $this->assertSame('整形済み', $result['record_text']);
    }

    public function test_壊れたjsonはパース失敗として扱われる(): void
    {
        try {
            $this->validator->validate($this->response('{"record_text": "途中で切れ'), ['type' => 'object']);
            $this->fail('例外が投げられるべき');
        } catch (LlmException $e) {
            $this->assertSame(LlmErrorType::JsonParse, $e->errorType);
            $this->assertTrue($e->isCorrectable(), '修正して再実行できる失敗であるべき');
        }
    }

    // ---------------------------------------------------------------
    // union 型と null の扱い
    // ---------------------------------------------------------------

    public function test_nullを許す項目はnullでも通る(): void
    {
        // 「わからないものは埋めない」ことを許すための扱い
        $result = $this->validator->validate(
            $this->response('{"water_ml":null}'),
            ['type' => 'object', 'properties' => ['water_ml' => ['type' => ['integer', 'null']]]],
        );

        $this->assertNull($result['water_ml']);
    }

    public function test_nullを許さない項目がnullなら不一致になる(): void
    {
        $this->expectException(LlmException::class);

        $this->validator->validate(
            $this->response('{"record_text":null}'),
            ['type' => 'object', 'properties' => ['record_text' => ['type' => 'string']]],
        );
    }

    public function test_union型は列挙されたどれかに合えば通る(): void
    {
        $result = $this->validator->validate(
            $this->response('{"water_ml":960}'),
            ['type' => 'object', 'properties' => ['water_ml' => ['type' => ['integer', 'null']]]],
        );

        $this->assertSame(960, $result['water_ml']);
    }

    // ---------------------------------------------------------------
    // 不一致の指摘
    // ---------------------------------------------------------------

    public function test_必須項目の欠落は項目名を挙げて指摘される(): void
    {
        // この文面がそのまま修正依頼のプロンプトになる
        try {
            $this->validator->validate(
                $this->response('{"record_text":"本文"}'),
                ['type' => 'object', 'required' => ['record_text', 'family_text', 'handover_note']],
            );
            $this->fail('例外が投げられるべき');
        } catch (LlmException $e) {
            $this->assertSame(LlmErrorType::SchemaMismatch, $e->errorType);
            $this->assertStringContainsString('family_text', $e->getMessage());
            $this->assertStringContainsString('handover_note', $e->getMessage());
        }
    }

    public function test_違反は一度にすべて集めて返す(): void
    {
        // 1件ずつ指摘して投げ直すと、そのたびに課金が発生する
        try {
            $this->validator->validate(
                $this->response('{}'),
                ['type' => 'object', 'required' => ['a', 'b', 'c']],
            );
            $this->fail('例外が投げられるべき');
        } catch (LlmException $e) {
            $this->assertSame(3, substr_count($e->getMessage(), '必須項目'));
        }
    }

    public function test_配列の要素も1件ずつ検証される(): void
    {
        try {
            $this->validator->validate(
                $this->response('{"risks":[{"severity":"high"},{"severity":"unknown"}]}'),
                [
                    'type' => 'object',
                    'properties' => [
                        'risks' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'severity' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
                                ],
                            ],
                        ],
                    ],
                ],
            );
            $this->fail('例外が投げられるべき');
        } catch (LlmException $e) {
            $this->assertStringContainsString('risks[1]', $e->getMessage());
            $this->assertStringNotContainsString('risks[0]', $e->getMessage());
        }
    }

    // ---------------------------------------------------------------

    private function response(string $text): LlmResponse
    {
        return new LlmResponse(
            text: $text,
            parsed: null,
            model: 'claude-opus-5',
            stopReason: 'end_turn',
            usage: new TokenUsage(inputTokens: 100, outputTokens: 50),
            latencyMs: 500,
        );
    }
}
