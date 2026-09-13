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
 */
final class JsonSchema
{
    /**
     * すべての object ノードに additionalProperties: false を補う。
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public static function forApi(array $schema): array
    {
        if (self::isObjectNode($schema)) {
            $schema['additionalProperties'] = false;
        }

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
