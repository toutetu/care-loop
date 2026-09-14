<?php

namespace Tests\Unit;

use App\Llm\Prompts\GoalProgressPrompt;
use App\Llm\Prompts\RiskDetectionPrompt;
use App\Llm\Prompts\VoiceTransformPrompt;
use App\Llm\Support\JsonSchema;
use PHPUnit\Framework\TestCase;

/**
 * 構造化出力のスキーマを、APIが受け付ける形へ正規化できているか。
 *
 * ここで確かめたいことは、APIを呼ばずに400を防げるかである。
 * スキーマの不備は実行するまで気づけず、しかも失敗は本番でしか起きない。
 */
class JsonSchemaTest extends TestCase
{
    // ---------------------------------------------------------------
    // additionalProperties
    // ---------------------------------------------------------------

    public function test_入れ子のobjectにもadditional_propertiesを補う(): void
    {
        $schema = JsonSchema::forApi([
            'type' => 'object',
            'properties' => [
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'nested' => ['type' => 'object', 'properties' => []],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertFalse($schema['additionalProperties']);
        $this->assertFalse($schema['properties']['items']['items']['additionalProperties']);
        $this->assertFalse(
            $schema['properties']['items']['items']['properties']['nested']['additionalProperties'],
        );
    }

    public function test_object以外にはadditional_propertiesを付けない(): void
    {
        $schema = JsonSchema::forApi(['type' => 'string']);

        $this->assertArrayNotHasKey('additionalProperties', $schema);
    }

    // ---------------------------------------------------------------
    // enum と union 型
    // ---------------------------------------------------------------

    public function test_enumとunion型の併記ではtypeを落とす(): void
    {
        // APIはこの組み合わせを400で拒否する。
        //   Enum value 0 does not match declared type '['integer', 'null']'
        // enum が取りうる値を列挙しているので、type を落としても制約は弱まらない。
        $schema = JsonSchema::forApi([
            'type' => ['integer', 'null'],
            'enum' => [0, 30, 50, 80, 100, null],
        ]);

        $this->assertArrayNotHasKey('type', $schema);
        $this->assertSame([0, 30, 50, 80, 100, null], $schema['enum']);
    }

    public function test_単一のtypeとenumの併記はそのまま残す(): void
    {
        // こちらはAPIが受け付ける。通るものまで削ると情報が減るだけになる。
        $schema = JsonSchema::forApi([
            'type' => 'string',
            'enum' => ['same_day', 'next_visit'],
        ]);

        $this->assertSame('string', $schema['type']);
    }

    public function test_enumのないunion型はそのまま残す(): void
    {
        $schema = JsonSchema::forApi(['type' => ['integer', 'null']]);

        $this->assertSame(['integer', 'null'], $schema['type']);
    }

    // ---------------------------------------------------------------
    // 実際に使っているスキーマ
    // ---------------------------------------------------------------

    /**
     * 本番で使うスキーマに、APIが拒否する組み合わせが残っていないか。
     *
     * 個別の記法を直しても、別のプロンプトで同じ書き方をすれば同じ失敗が起きる。
     * 送信直前の正規化が全プロンプトに効いていることを、まとめて確かめる。
     */
    public function test_全プロンプトのスキーマが正規化される(): void
    {
        $schemas = [
            'F-LLM-05' => (new VoiceTransformPrompt)->schema(),
            'F-LLM-02' => (new RiskDetectionPrompt)->schema(),
            'F-LLM-01' => (new GoalProgressPrompt)->schema(),
        ];

        foreach ($schemas as $feature => $schema) {
            $this->assertValid(JsonSchema::forApi($schema), $feature);
        }
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function assertValid(array $schema, string $path): void
    {
        if (($schema['type'] ?? null) === 'object') {
            $this->assertSame(
                false,
                $schema['additionalProperties'] ?? null,
                "{$path}: object には additionalProperties: false が要る",
            );
        }

        $this->assertFalse(
            isset($schema['enum']) && is_array($schema['type'] ?? null),
            "{$path}: enum と union 型は併記できない",
        );

        foreach ($schema['properties'] ?? [] as $key => $child) {
            if (is_array($child)) {
                $this->assertValid($child, "{$path}.{$key}");
            }
        }

        if (is_array($schema['items'] ?? null)) {
            $this->assertValid($schema['items'], "{$path}[]");
        }
    }
}
