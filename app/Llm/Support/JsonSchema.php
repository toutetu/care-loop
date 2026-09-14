<?php

namespace App\Llm\Support;

/**
 * 構造化出力へ渡すスキーマを、API が受け付ける形へ正規化する。
 *
 * 【なぜ必要か】
 * Claude API の構造化出力では、object 型のすべてのノードに
 * additionalProperties: false を明示する必要がある。書き忘れると
 * 400 invalid_request_error になる。
 *
 *   output_config.format.schema: For 'object' type,
 *   'additionalProperties' must be explicitly set to false
 *
 * これを各プロンプトクラスの書き手に覚えさせるのは筋が悪い。
 * ネストが深くなるほど書き忘れが起きるうえ、忘れても実行するまで気づけない。
 * 送信直前に機械的に付与することで、書き忘れを構造的になくす。
 *
 * 【スキーマの記法について】
 * null を許す項目は、OpenAPI の nullable: true ではなく
 * JSON Schema 標準の type: ["integer", "null"] で表現する。
 * API が解釈するのは JSON Schema であるため。
 *
 * 【enum と union 型を併記できない】
 * type を union で書いたノードに enum を併記すると、API は 400 を返す。
 *
 *   output_config.format.schema: Invalid schema:
 *   Enum value 0 does not match declared type '['integer', 'null']'
 *
 * JSON Schema の仕様上は正しい書き方だが、API の検証はこれを通さない。
 * enum があれば取りうる値はそれで確定するため、type を落として送る。
 */
final class JsonSchema
{
    /**
     * API が受け付ける形へ整える。
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public static function forApi(array $schema): array
    {
        if (self::isObjectNode($schema)) {
            $schema['additionalProperties'] = false;
        }

        $schema = self::dropTypeBesideEnum($schema);

        if (isset($schema['properties']) && is_array($schema['properties'])) {
            foreach ($schema['properties'] as $key => $child) {
                if (is_array($child)) {
                    /** @var array<string, mixed> $child */
                    $schema['properties'][$key] = self::forApi($child);
                }
            }
        }

        if (isset($schema['items']) && is_array($schema['items'])) {
            /** @var array<string, mixed> $items */
            $items = $schema['items'];
            $schema['items'] = self::forApi($items);
        }

        return $schema;
    }

    /**
     * union 型と enum の併記を解消する。
     *
     * enum が列挙している値がそのまま取りうる値の全体なので、type を落としても
     * 制約は弱まらない。type が単一の文字列のときは API も受け付けるため
     * そのまま残す。情報を落とすのは、落とさないと通らない場合に限る。
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private static function dropTypeBesideEnum(array $schema): array
    {
        // type を書いていないノードもある（enum だけで値が定まる場合）。
        // 未定義の添字を読まないよう ?? を挟む。
        if (isset($schema['enum']) && is_array($schema['type'] ?? null)) {
            unset($schema['type']);
        }

        return $schema;
    }

    /**
     * type が "object" そのもの、または ["object", "null"] のような
     * union で object を含む場合に true を返す。
     *
     * @param  array<string, mixed>  $schema
     */
    private static function isObjectNode(array $schema): bool
    {
        $type = $schema['type'] ?? null;

        if (is_string($type)) {
            return $type === 'object';
        }

        return is_array($type) && in_array('object', $type, true);
    }
}
