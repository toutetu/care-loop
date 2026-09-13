<?php

namespace App\Llm\Prompts;

use App\Enums\LlmFeature;

/**
 * 機能ごとのプロンプトと出力スキーマ。
 *
 * 【プロンプトをクラスとして切り出す理由】
 * プロンプトは仕様そのものであり、コードの中に文字列として埋もれさせると
 * 変更履歴も検証も追えなくなる。ファイルとして独立させることで、
 * Gitで差分が見え、出力品質が変わったときに「いつ何を変えたか」を追える。
 *
 * llm_requests には prompt_version を記録しているため、
 * 「このプロンプトのときの出力」と突き合わせて評価できる。
 */
interface FeaturePrompt
{
    public function feature(): LlmFeature;

    /**
     * 毎回同じ内容になる部分。プロンプトキャッシュの対象になる。
     *
     * ここに対象期間や記録本文といった可変の値を混ぜてはいけない。
     * キャッシュが一切効かなくなり、入力トークンの課金が跳ね上がる。
     */
    public function systemPrompt(): string;

    /**
     * 期待する出力の JSON Schema。
     *
     * 構造化出力の指定としてAPIへ渡すと同時に、受け取った応答の検証にも使う。
     * モデル側に形を守らせたうえで、こちらでも検証する（多層の確認）。
     *
     * @return array<string, mixed>
     */
    public function schema(): array;
}
