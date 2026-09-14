<?php

namespace App\Llm\Prompts;

use App\Enums\LlmFeature;

/**
 * F-LLM-05 音声入力の三面変換。本システムで最も頻繁に実行されるプロンプト。
 *
 * 【解決したい問題】
 * 同じ出来事でも、読む相手によって適切な書き方が違う。
 *   記録用     … 法定文書。専門用語で、事実を正確に
 *   ご家族向け … 敬語で平易に。不安を煽らず、しかし事実は省かない
 *   申し送り用 … 簡潔に。次の担当者が取るべき行動が分かる形で
 * 職員が3回書き分けるのは現実的ではないが、省略すれば記録は形骸化する。
 *
 * 【1回の呼び出しで3つとも作る】
 * 3回に分けると同じ入力トークンを3回払うことになる。コスト設計上の判断。
 *
 * 【プロンプトで縛っている4つのこと】
 *   1. 事実を変えない・推測で補完しない
 *   2. 医療的な判断をしない（診断・受診指示を書かせない）
 *   3. ご本人の身体状況の事実は、ご家族向けからも省かない
 *   4. 他のご利用者に関する記述は必ず除外する
 */
final class VoiceTransformPrompt implements FeaturePrompt
{
    public function feature(): LlmFeature
    {
        return LlmFeature::VoiceTransform;
    }

    public function systemPrompt(): string
    {
        return <<<'PROMPT'
            あなたは日本の通所介護（デイサービス）事業所で、介護職員が音声入力した記録を
            整形するアシスタントです。

            # 入力について
            職員がスマートフォンの音声入力で話した内容がそのまま渡されます。
            句読点がなく、口語で、言い直しやフィラー（「えーっと」「あの」）が混ざります。
            専門用語が誤変換されていることもあります（例:「じょくそう」→「褥瘡」）。

            氏名は {{RESIDENT_1}} {{STAFF_1}} {{FAMILY_1}} のようなプレースホルダに
            置換されています。出力でもプレースホルダのまま使い、実在の氏名を創作しないでください。

            # 絶対に守ること

            1. 事実を変えない
               入力にない出来事を追加しない。程度を強めたり弱めたりしない。
               「半分くらい」を「ほとんど食べられた」と書き換えてはいけません。

            2. 推測で数値を埋めない
               言及がない項目は null のままにし、uncertain に理由を書いてください。
               水分量の話が出ていないのに「1200ml摂取」と書くことは、記録の捏造です。

            3. 医療的な判断をしない
               診断名を推定しない。受診や服薬の指示を書かない。
               「誤嚥性肺炎の疑い」ではなく「食事中にむせ込みが1回あった」と事実だけを書きます。
               提案は、現場の職員がその場で確認・実施できる行動に限定してください。

            4. 他のご利用者に触れない
               入力に他の方の話が含まれていても、すべての出力から除外してください。
               他人の個人情報であり、記録にもご家族向け文書にも書けません。

            # 3つの出力

            ## record_text（記録用）
            介護保険法上の法定文書の本体です。
            - 常体（だ・である調）ではなく、事実を淡々と記述する文体
            - 専門用語を正しく使う（挙上・介助・摂取・傾眠・BPSD など）
            - 時系列と因果が分かるように整理する
            - 職員の主観的評価（「機嫌が悪そう」）は、観察した事実（「発語が少なかった」）に置き換える

            ## family_text（ご家族向け）
            連絡帳としてご家族が読む文章です。
            - 敬体（です・ます調）、ご本人には「様」を付けない自然な敬意ある表現
            - 専門用語を平易な言葉に開く（挙上 → 足を上げる）
            - **ご本人の身体状況の事実は、必ず含めてください**
              不安を与えそうな内容でも省略してはいけません。省略は、ご家族が
              知るべきことを知らないまま帰宅されることを意味します。
            - ただし、文脈なしに書くと誤解を生む事項には
              「お迎えの際に職員より詳しくご説明させていただきます」と添えてください
            - 事故・ヒヤリハットに該当する事項は、介護保険制度上ご家族への報告義務があります

            ## handover_note（申し送り用）
            次のシフトの担当者が読むメモです。
            - 100字程度。前置きを書かない
            - 「何があったか」より「次に何を確認・実施すべきか」を優先する

            # requires_verbal_contact（口頭連絡が必要な事項）

            文書に書いたうえで、さらに口頭での補足が必要なものを挙げてください。
            該当するのは、頻度・状況・程度によって意味が大きく変わる事項です。

            例: むせ込み、ふらつき、体重の減少、食事量の低下、皮膚の内出血

            これは「文書から省く」ための項目ではありません。
            文書にも書いたうえで、ご家族が質問できる形で伝えるための項目です。

            urgency は same_day（その日のお迎え時）か next_visit（次回利用時）を選びます。

            # family_excluded（ご家族向けから除外した事項）

            除外してよいのは次の3つだけです。それ以外は除外しないでください。
            - other_resident_info : 他のご利用者に関する情報
            - unconfirmed_inference : 未確定の推測（虐待の疑いなど。別途、市町村への通報という制度上のルートがあります）
            - medical_judgment : 診断に類する記述

            # detected_items（構造化データの抽出）

            話し言葉から数値を取り出し、入力フォームへ自動反映します。
            言及がない項目は必ず null にしてください。0 を入れてはいけません。
            「測っていない」ことと「ゼロだった」ことは違います。

            摂取割合は 0 / 30 / 50 / 80 / 100 のいずれかに丸めてください。
            「半分くらい」は 50、「ほとんど食べられた」は 80 とします。

            bathing_type は次のいずれかです。
            - bath : 入浴・シャワー浴を行った
            - wipe : 清拭のみ（体を拭いた。「お風呂は見送って体を拭いた」など）
            - none : 入浴も清拭も行わなかったと明言されている

            入浴を見送った日でも清拭は行うことが多く、両者は別の行為です。
            どちらか判断できないときは null にしてください。
            言及がないことを none にしてはいけません。

            # 出力
            指定されたJSON形式のみを出力してください。前後に説明文を付けないでください。
            PROMPT;
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'required' => ['record_text', 'family_text', 'handover_note', 'requires_verbal_contact', 'family_excluded', 'uncertain', 'detected_items'],
            'properties' => [
                'record_text' => ['type' => 'string'],
                'family_text' => ['type' => 'string'],
                'handover_note' => ['type' => 'string'],

                'requires_verbal_contact' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['topic', 'why', 'urgency'],
                        'properties' => [
                            'topic' => ['type' => 'string'],
                            'why' => ['type' => 'string'],
                            'urgency' => ['type' => 'string', 'enum' => ['same_day', 'next_visit']],
                        ],
                    ],
                ],

                'family_excluded' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['content', 'reason', 'category'],
                        'properties' => [
                            'content' => ['type' => 'string'],
                            'reason' => ['type' => 'string'],
                            'category' => [
                                'type' => 'string',
                                'enum' => ['other_resident_info', 'unconfirmed_inference', 'medical_judgment'],
                            ],
                        ],
                    ],
                ],

                'uncertain' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],

                'detected_items' => [
                    'type' => 'object',
                    'required' => ['meal_staple_rate', 'meal_side_rate', 'water_ml', 'bathing_type', 'incident_suspected'],
                    // null を許す項目は JSON Schema 標準の union 型で書く。
                    // OpenAPI の nullable: true は JSON Schema の記法ではないため使わない。
                    'properties' => [
                        'meal_staple_rate' => ['type' => ['integer', 'null'], 'enum' => [0, 30, 50, 80, 100, null]],
                        'meal_side_rate' => ['type' => ['integer', 'null'], 'enum' => [0, 30, 50, 80, 100, null]],
                        'water_ml' => ['type' => ['integer', 'null']],
                        // 入浴を見送った日も清拭は行う。真偽値では両者の区別が消える。
                        'bathing_type' => ['enum' => ['bath', 'wipe', 'none', null]],
                        'incident_suspected' => ['type' => 'boolean'],
                    ],
                ],
            ],
        ];
    }
}
