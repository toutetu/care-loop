<?php

namespace App\Llm\Prompts;

use App\Enums\LlmFeature;
use App\Enums\ProgressStatus;

/**
 * F-LLM-01 通所介護計画 目標進捗要約。
 *
 * モニタリング（計画書の目標に対する達成状況の振り返り）の下書きを作る。
 * 従来は1〜3ヶ月分の記録を人手で読み返して要約しており、担当者の記憶と
 * 主観に依存しやすかった。その出発点を機械化する。
 *
 * 【insufficient_data を必ず選択肢に入れる】
 * 記録が不足している期間に、無理やり「改善」「横ばい」と評価させないため。
 * 「判断できる材料が不足している」と言えることが、事実に基づかない出力を防ぐ
 * 最も効果的な手段である。この選択肢を外すと、LLMは必ずどれかを選ばざるを
 * 得なくなり、根拠の薄い評価が混ざり始める。
 *
 * 【数値は渡す、数えさせない】
 * 「1日1,200ml以上の水分摂取ができる」のような目標の達成回数は、
 * システム側で集計してプロンプトに含める。LLMには、その数値と自由記述を
 * 踏まえた評価だけを求める。
 */
final class GoalProgressPrompt implements FeaturePrompt
{
    public function feature(): LlmFeature
    {
        return LlmFeature::GoalProgress;
    }

    public function systemPrompt(): string
    {
        $statuses = implode("\n", array_map(
            static fn (ProgressStatus $s): string => "- {$s->value} : {$s->label()}",
            ProgressStatus::cases(),
        ));

        return <<<PROMPT
            あなたは日本の通所介護（デイサービス）事業所で、通所介護計画書の
            モニタリング（目標に対する達成状況の振り返り）を下書きする役割を担います。

            氏名は {{RESIDENT_1}} {{STAFF_1}} のようなプレースホルダに置換されています。
            出力でもプレースホルダのまま使ってください。

            # 入力
            - 短期目標の一覧（それぞれに goal_id が付いています）
            - 対象期間の集計値（利用回数・水分摂取・食事摂取率・体重・バイタル等）
            - 対象期間の自由記述の記録（それぞれに記録IDが付いています）

            集計値はシステム側で算出済みです。数え直す必要はありません。

            # 求めること

            短期目標の1件ごとに、その目標に対する進捗を評価してください。
            goal_id は入力で与えられたものをそのまま使ってください。作らないでください。

            ## progress_status
            {$statuses}

            **記録から判断できないときは insufficient_data を選んでください。**
            無理にどれかを選ぶ必要はありません。対象期間の記録が少ない、
            その目標に関係する記述が見当たらない、という状況は実際によくあります。
            「判断できない」と書くことは、誤った評価を書くより遥かに有用です。

            ## comment
            150字以内。なぜその評価にしたのかが分かるように書いてください。
            「概ね良好です」のような、何も言っていない文は書かないでください。

            ## evidence
            **すべての評価に根拠を添えてください。**
            記録から判断した場合は、記録ID（record_id）と該当箇所の引用（excerpt）を
            付けてください。引用は記録に実在する文字列に限ります。要約しないでください。

            集計値から判断した場合は record_id を null にし、
            excerpt に「水分摂取 13回中4回が目標達成」のように集計結果を書いてください。

            insufficient_data の場合は evidence を空配列にして構いません。

            # overall_summary
            期間全体の総括を200字以内で書いてください。
            目標ごとの評価を並べ直すのではなく、期間を通して見えた変化を書いてください。

            # next_actions
            次期に向けた提案を最大3件。現場の職員が実施できる内容に限ります。
            「受診を検討」のような医療的な判断は書かないでください。

            # confidence
            - high   : 十分な記録があり、自信を持って評価できる
            - medium : 評価はできるが、判断材料がやや不足している
            - low    : 記録が少なく、評価の確度が低い

            正直に申告してください。画面では low の結果に警告を表示し、
            そのままモニタリング記録へ転記されないようにしています。

            # 守ること
            - 事実を変えない。記録にない出来事を追加しない
            - 診断名を推定しない。受診や服薬の指示を書かない
            - 他のご利用者に関する記述は含めない

            # 出力
            指定されたJSON形式のみを出力してください。前後に説明文を付けないでください。
            PROMPT;
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'required' => ['overall_summary', 'goals', 'next_actions', 'confidence'],
            'properties' => [
                'overall_summary' => ['type' => 'string'],
                'confidence' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
                'next_actions' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'goals' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['goal_id', 'progress_status', 'comment', 'evidence'],
                        'properties' => [
                            'goal_id' => ['type' => 'integer'],
                            'progress_status' => ['type' => 'string', 'enum' => ProgressStatus::values()],
                            'comment' => ['type' => 'string'],
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
                        ],
                    ],
                ],
            ],
        ];
    }
}
