<?php

namespace App\Llm\Prompts;

use App\Enums\LlmFeature;
use App\Enums\RiskCategory;

/**
 * F-LLM-02 リスク兆候の抽出。
 *
 * 【役割分担がこの機能の核心】
 * 体重減少率・水分摂取量・バイタルの異常値といった「数値で判定できるもの」は、
 * RiskIndicatorCalculator が決定的に算出済みである。
 * LLMには、その結果を渡したうえで「数値に現れない変化」だけを求める。
 *
 * こうする理由は3つある。
 *   1. 算術をLLMに任せると再現性が失われ、検証できなくなる
 *   2. 同じ指摘を二度させても価値がなく、トークンの無駄になる
 *   3. 役割を絞ったほうが、質的な兆候の検出精度が上がる
 *
 * 【「該当なし」と言えるようにする】
 * no_risk_detected を用意し、無理にリスクを挙げなくてよいことを明示する。
 * 何か書かなければならない状態に追い込むと、根拠の薄い指摘が混ざり始める。
 */
final class RiskDetectionPrompt implements FeaturePrompt
{
    public function feature(): LlmFeature
    {
        return LlmFeature::RiskDetection;
    }

    public function systemPrompt(): string
    {
        $categories = implode(' / ', array_map(
            static fn (RiskCategory $c): string => "{$c->value}（{$c->label()}）",
            RiskCategory::cases(),
        ));

        return <<<PROMPT
            あなたは日本の通所介護（デイサービス）事業所で、ご利用者の記録から
            リスクの兆候を読み取る役割を担います。

            氏名は {{RESIDENT_1}} {{STAFF_1}} のようなプレースホルダに置換されています。
            出力でもプレースホルダのまま使ってください。

            # あなたに求める範囲

            数値で判定できるリスク（体重減少率・水分摂取量・食事摂取率・体温・血圧・SpO2・
            ヒヤリハットの件数）は、システム側ですでに算出済みです。
            算出済みの指標は入力に含めて渡します。

            **同じ内容を繰り返さないでください。**
            あなたに求めるのは、数値に現れない、自由記述を読まなければ分からない変化です。

            たとえば次のようなものです。
            - 「ふらつき」「足が上がらない」といった記述の頻度が増えている
            - レクリエーションへの参加時間が短くなっている
            - 発語や表情に関する記述が変化している
            - 特定の場面（入浴・移乗・排泄）でのみ介助量が増えている
            - 訴えの内容が変わってきている（痛みの部位が移動している等）

            算出済みの指標について「さらに詳しく」書く必要はありません。
            記述から新たに読み取れることがある場合にのみ、別の指摘として挙げてください。

            # 守ること

            1. 根拠のない指摘をしない
               すべての指摘に evidence を付け、必ず記録ID（record_id）と
               該当箇所の引用（excerpt）を添えてください。
               引用は記録に実在する文字列に限ります。要約や言い換えをしないでください。

            2. 医療的な判断をしない
               診断名を推定しない。受診や服薬の指示を書かない。
               「脳梗塞の前兆の可能性」ではなく「ふらつきの記述が3週間で4件に増えている」と、
               観察された事実の変化だけを述べてください。

            3. suggested_actions は現場で実施できる行動に限る
               「受診を検討」ではなく「送迎車の乗降を2名介助に変更する」のように、
               職員がその場で判断・実施できる内容にしてください。

            4. 該当がなければ no_risk_detected を true にする
               無理に挙げる必要はありません。記録から読み取れる変化がないことは、
               それ自体が有用な情報です。

            5. confidence を正直に申告する
               対象期間の記録が少ない、自由記述が短い、といった場合は low にしてください。
               画面では低確信度の結果に警告を表示します。

            # category に使える値
            {$categories}

            # severity の目安
            - high   : その日のうちに対応を検討すべき
            - medium : 数日のうちに確認したい
            - low    : 記録として残し、経過を見る

            # 出力
            指定されたJSON形式のみを出力してください。前後に説明文を付けないでください。
            PROMPT;
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'required' => ['no_risk_detected', 'confidence', 'risks'],
            'properties' => [
                'no_risk_detected' => ['type' => 'boolean'],
                'confidence' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
                'risks' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['category', 'severity', 'title', 'reason', 'evidence', 'suggested_actions'],
                        'properties' => [
                            'category' => ['type' => 'string', 'enum' => RiskCategory::values()],
                            'severity' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
                            'title' => ['type' => 'string'],
                            'reason' => ['type' => 'string'],
                            'evidence' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'required' => ['record_id', 'date', 'excerpt'],
                                    'properties' => [
                                        'record_id' => ['type' => ['integer', 'null']],
                                        'date' => ['type' => 'string'],
                                        'excerpt' => ['type' => 'string'],
                                    ],
                                ],
                            ],
                            'suggested_actions' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
