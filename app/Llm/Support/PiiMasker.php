<?php

namespace App\Llm\Support;

/**
 * 外部LLMへ送信する前に、個人を特定する文字列をプレースホルダへ置換する。
 *
 * 【なぜ必要か】
 * 介護記録は個人情報保護法上の「要配慮個人情報」（健康状態・病歴を含む）に
 * 該当する。外部サービスへ送る内容は最小限にし、誰の記録かを特定できない形に
 * してから送る（要件定義 7.2節）。
 *
 * 【使い方】
 *   $masker = new PiiMasker();
 *   $placeholder = $masker->register(PiiMasker::RESIDENT, $resident->name); // {{RESIDENT_1}}
 *   $maskedText  = $masker->mask($record->raw_note);
 *   // ... LLMへ送信し、応答を受け取る ...
 *   $restored    = $masker->unmask($response);  // 画面表示時に実名へ戻す
 *
 * 【対応表の扱い】
 * 対応表はこのインスタンスの生存期間だけメモリに保持し、永続化しない。
 * 監査ログ（llm_requests）にはマスキング後のペイロードのみを保存する。
 * ログ経由で個人情報が二次流出することを防ぐため。
 *
 * 【限界（隠さず明記する）】
 * 自由記述に「登録されていない人名」が書かれていた場合は検出できない。
 * 例：他のご利用者のお名前、ご家族のお名前を職員が本文に書いた場合。
 * 完全な匿名化は技術だけでは達成できないため、次の2点で補う。
 *   1. 事業所に登録されている利用者・職員・家族名はすべて事前登録して置換する
 *   2. 「他のご利用者のお名前を記録に書かない」という運用ルールを画面で案内する
 * この限界を理解したうえで採用している。
 */
final class PiiMasker
{
    public const RESIDENT = 'RESIDENT';

    public const STAFF = 'STAFF';

    public const FAMILY = 'FAMILY';

    public const FACILITY = 'FACILITY';

    /** @var array<string, string> プレースホルダ => 実際の値 */
    private array $toReal = [];

    /** @var array<string, string> 実際の値 => プレースホルダ */
    private array $toPlaceholder = [];

    /** @var array<string, int> 種別ごとの採番カウンタ */
    private array $counters = [];

    /**
     * 置換対象を登録し、割り当てられたプレースホルダを返す。
     *
     * 同じ値を二度登録しても同じプレースホルダを返す。1人の利用者が
     * 記録のあちこちに登場しても、LLMからは同一人物として見えるようにするため。
     */
    public function register(string $type, ?string $value): ?string
    {
        $value = $this->normalize($value);

        if ($value === null) {
            return null;
        }

        if (isset($this->toPlaceholder[$value])) {
            return $this->toPlaceholder[$value];
        }

        $this->counters[$type] = ($this->counters[$type] ?? 0) + 1;
        $placeholder = sprintf('{{%s_%d}}', $type, $this->counters[$type]);

        $this->toPlaceholder[$value] = $placeholder;
        $this->toReal[$placeholder] = $value;

        // 「佐藤 ハナ」は本文中で「佐藤ハナ」「佐藤」とも書かれる。
        // 同じプレースホルダへ寄せることで、表記が違っても同一人物として扱う。
        foreach ($this->variantsOf($value) as $variant) {
            $this->toPlaceholder[$variant] ??= $placeholder;
        }

        return $placeholder;
    }

    /**
     * 登録済みの値をプレースホルダへ置換する。
     *
     * 長い文字列から先に置換する。「佐藤 ハナ」より先に「佐藤」を置換すると
     * 「{{RESIDENT_1}} ハナ」となり、下の名前が残ってしまうため。
     */
    public function mask(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        $search = array_keys($this->toPlaceholder);
        usort($search, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        foreach ($search as $value) {
            $text = str_replace($value, $this->toPlaceholder[$value], $text);
        }

        return $text;
    }

    /**
     * プレースホルダを実名へ戻す。LLMの応答を画面へ表示する直前に使う。
     */
    public function unmask(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        return str_replace(array_keys($this->toReal), array_values($this->toReal), $text);
    }

    /**
     * 配列（LLMの構造化レスポンス）の文字列値を再帰的に実名へ戻す。
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function unmaskArray(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $data[$key] = $this->unmask($value);
            } elseif (is_array($value)) {
                $data[$key] = $this->unmaskArray($value);
            }
        }

        return $data;
    }

    /**
     * 置換されずに残っている人名がないかを目視確認するための対応表。
     * デバッグとテスト専用。永続化してはならない。
     *
     * @return array<string, string> プレースホルダ => 実際の値
     */
    public function placeholders(): array
    {
        return $this->toReal;
    }

    public function count(): int
    {
        return count($this->toReal);
    }

    /**
     * 氏名の表記ゆれを列挙する。
     *
     * 姓のみの置換は、2文字以上のときだけ行う。1文字の姓（「林」など）を
     * 単独で置換すると、無関係な語の一部まで巻き込むため。
     *
     * @return list<string>
     */
    private function variantsOf(string $value): array
    {
        $variants = [];

        $withoutSpace = (string) preg_replace('/[\s\x{3000}]+/u', '', $value);
        if ($withoutSpace !== $value && $withoutSpace !== '') {
            $variants[] = $withoutSpace;
        }

        $parts = preg_split('/[\s\x{3000}]+/u', $value, -1, PREG_SPLIT_NO_EMPTY);

        if (is_array($parts) && count($parts) >= 2) {
            foreach ($parts as $part) {
                if (mb_strlen($part) >= 2) {
                    $variants[] = $part;
                }
            }
        }

        return $variants;
    }

    private function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
