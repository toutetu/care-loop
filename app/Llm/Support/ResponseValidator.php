<?php

namespace App\Llm\Support;

use App\Llm\Data\LlmResponse;
use App\Llm\Exceptions\LlmException;
use JsonException;

/**
 * LLMの応答を JSON として取り出し、期待するスキーマに合っているかを検証する。
 *
 * 【ここが LLM 連携で最も壊れやすい箇所】
 * JSONのパース失敗もスキーマ不一致も、HTTP としては 200 で返ってくる。
 * 通信は成功しているため、通常のHTTPエラーハンドリングでは捕捉できない。
 * 「APIは呼べたのに中身が使えない」という失敗を独立して扱う必要がある。
 *
 * 【構造化出力を指定していても検証する】
 * 実機で確認したところ、SDK の TextBlock::$parsed は populate されず、
 * JSONは本文として返ってきた。つまり構造化出力を指定していても、
 * 本文からのパースと検証は必要になる。
 * またスキーマに合っていても、根拠のない内容が入っていることはある。
 *
 * 【検証器を自前で書いている理由】
 * 汎用の JSON Schema ライブラリを入れる選択肢もあったが、採用しなかった。
 * 検証に失敗したとき、その内容を「修正依頼」としてLLMへ投げ直すため、
 * エラーメッセージ自体がプロンプトの一部になる。
 * 「required property 'evidence' missing」より
 * 「evidence（根拠）が入っていません。必ず記録IDを添えてください」のほうが
 * 修正が通りやすい。メッセージを自分で設計できることに価値があるため、
 * 使う範囲（type / required / properties / items / enum）に絞って実装した。
 */
final class ResponseValidator
{
    /**
     * 応答から構造化データを取り出して検証する。
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     *
     * @throws LlmException パース失敗またはスキーマ不一致のとき
     */
    public function validate(LlmResponse $response, array $schema): array
    {
        $data = $response->parsed ?? $this->decode($response->text);

        $violations = $this->check($data, $schema, '');

        if ($violations !== []) {
            throw LlmException::schemaMismatch($this->describe($violations));
        }

        return $data;
    }

    /**
     * 本文からJSONを取り出す。
     *
     * 構造化出力を指定していても、モデルがコードブロックで包んで返すことがある。
     * 前後の ``` と言語指定を取り除いてから解析する。
     *
     * @return array<string, mixed>
     *
     * @throws LlmException
     */
    private function decode(string $text): array
    {
        $trimmed = trim($text);

        // ```json ... ``` で囲まれている場合は中身だけを取り出す
        if (preg_match('/^```(?:json)?\s*(.+?)\s*```$/s', $trimmed, $matches) === 1) {
            $trimmed = $matches[1];
        }

        if ($trimmed === '') {
            throw LlmException::jsonParseFailed('応答が空でした。');
        }

        try {
            $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw LlmException::jsonParseFailed(
                'JSONとして解析できませんでした：'.$e->getMessage()
            );
        }

        if (! is_array($decoded)) {
            throw LlmException::jsonParseFailed('JSONオブジェクトではありませんでした。');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * スキーマとの突き合わせ。見つかった問題をすべて集めて返す。
     *
     * 最初の1件で打ち切らないのは、修正依頼を1回で済ませたいため。
     * 1件ずつ指摘して投げ直すと、そのたびに課金が発生する。
     *
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    private function check(mixed $data, array $schema, string $path): array
    {
        $violations = [];
        $label = $path === '' ? 'ルート' : $path;
        $types = $this->typesOf($schema);

        if ($types !== []) {
            if ($data === null) {
                // null が許されているなら、それ以上は検証しない。
                // 「わからないものは埋めない」ことを許すための扱い。
                if (in_array('null', $types, true)) {
                    return [];
                }

                return [sprintf('%s は null にできません。', $label)];
            }

            if (! $this->matchesAnyType($data, $types)) {
                return [sprintf(
                    '%s は %s である必要がありますが、%s でした。',
                    $label,
                    implode(' または ', $types),
                    get_debug_type($data),
                )];
            }
        }

        if (isset($schema['enum']) && is_array($schema['enum']) && ! in_array($data, $schema['enum'], true)) {
            $allowed = implode(' / ', array_map(
                static fn (mixed $v): string => (string) json_encode($v, JSON_UNESCAPED_UNICODE),
                $schema['enum'],
            ));

            return [sprintf(
                '%s に使える値は %s のいずれかですが、%s でした。',
                $label,
                $allowed,
                (string) json_encode($data, JSON_UNESCAPED_UNICODE),
            )];
        }

        if (in_array('object', $types, true) && is_array($data)) {
            $required = $schema['required'] ?? [];

            if (is_array($required)) {
                foreach ($required as $key) {
                    if (! is_string($key)) {
                        continue;
                    }

                    if (! array_key_exists($key, $data)) {
                        $violations[] = sprintf('%s に必須項目 %s がありません。', $label, $key);
                    }
                }
            }

            $properties = $schema['properties'] ?? [];

            if (is_array($properties)) {
                foreach ($properties as $key => $childSchema) {
                    if (! is_string($key) || ! is_array($childSchema) || ! array_key_exists($key, $data)) {
                        continue;
                    }

                    $violations = [
                        ...$violations,
                        ...$this->check($data[$key], $childSchema, $path === '' ? $key : "{$path}.{$key}"),
                    ];
                }
            }
        }

        if (in_array('array', $types, true) && is_array($data) && isset($schema['items']) && is_array($schema['items'])) {
            /** @var array<string, mixed> $itemSchema */
            $itemSchema = $schema['items'];

            foreach (array_values($data) as $index => $item) {
                $violations = [
                    ...$violations,
                    ...$this->check($item, $itemSchema, "{$label}[{$index}]"),
                ];
            }
        }

        return $violations;
    }

    /**
     * type は文字列でも配列でも書ける（JSON Schema の union 型）。
     * null を許す項目は ["integer", "null"] のように書く。
     *
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    private function typesOf(array $schema): array
    {
        $type = $schema['type'] ?? null;

        if (is_string($type)) {
            return [$type];
        }

        if (is_array($type)) {
            return array_values(array_filter($type, is_string(...)));
        }

        return [];
    }

    /**
     * @param  list<string>  $types
     */
    private function matchesAnyType(mixed $data, array $types): bool
    {
        foreach ($types as $type) {
            if ($this->matchesType($data, $type)) {
                return true;
            }
        }

        return false;
    }

    private function matchesType(mixed $data, string $type): bool
    {
        return match ($type) {
            // PHPの連想配列に変換した時点で、JSONの {} と [] はどちらも [] になり
            // 区別できない。空のときは型判定を通し、required 項目の検査で弾く。
            'object' => is_array($data) && ($data === [] || ! array_is_list($data)),
            'array' => is_array($data) && array_is_list($data),
            'string' => is_string($data),
            'integer' => is_int($data),
            'number' => is_int($data) || is_float($data),
            'boolean' => is_bool($data),
            'null' => $data === null,
            default => true,
        };
    }

    /**
     * 違反の一覧を、そのままLLMへ渡せる修正依頼の文面にする。
     *
     * @param  list<string>  $violations
     */
    private function describe(array $violations): string
    {
        return "出力が期待した形式と一致しませんでした。\n・".implode("\n・", $violations);
    }
}
