<?php

namespace App\Llm\Clients;

use App\Enums\LlmErrorType;
use App\Enums\LlmFeature;
use App\Llm\Contracts\LlmClient;
use App\Llm\Data\LlmRequest;
use App\Llm\Data\LlmResponse;
use App\Llm\Data\TokenUsage;
use App\Llm\Exceptions\LlmException;

/**
 * 実APIを呼ばずに固定の応答を返す実装。
 *
 * 【2つの役割】
 *
 * 1. デモモード
 *    APIキーが未設定でもアプリ全体が動く。リポジトリをクローンした人が、
 *    課金設定をせずに画面とデータの流れを確認できる。
 *
 * 2. 異常系のテスト
 *    レート制限・認証エラー・タイムアウト・壊れたJSON・スキーマ不一致を
 *    意図的に起こせる。実APIではこれらを再現できないため、
 *    エラーハンドリングを検証する唯一の手段になる。
 *
 * 【テストでの使い方】
 *   $fake = new FakeClient();
 *   $fake->queueFailure(LlmErrorType::RateLimit)   // 1回目は429
 *        ->queueDefaultFor(LlmFeature::VoiceTransform); // 2回目は成功
 *   // -> バックオフして再送し、最終的に成功することを検証できる
 */
final class FakeClient implements LlmClient
{
    /** @var list<LlmResponse|LlmException> 先頭から順に返す予定の応答 */
    private array $queue = [];

    /** @var list<LlmRequest> 受け取ったリクエスト（送信内容の検証に使う） */
    private array $received = [];

    public function send(LlmRequest $request): LlmResponse
    {
        $this->received[] = $request;

        $next = array_shift($this->queue);

        if ($next instanceof LlmException) {
            throw $next;
        }

        return $next ?? $this->defaultResponse($request);
    }

    public function name(): string
    {
        return 'fake';
    }

    // ---------------------------------------------------------------
    // テスト用の組み立て
    // ---------------------------------------------------------------

    /**
     * 次の応答として、この構造化データを返す。
     *
     * @param  array<string, mixed>  $parsed
     */
    public function queueParsed(array $parsed, ?string $stopReason = 'end_turn'): self
    {
        $this->queue[] = $this->makeResponse($parsed, $stopReason);

        return $this;
    }

    /**
     * 構造化出力が得られず、本文だけが返ってきた状況を作る。
     * JSONとして壊れた文字列を渡せば、パース失敗の経路を検証できる。
     */
    public function queueRawText(string $text, ?string $stopReason = 'end_turn'): self
    {
        $this->queue[] = new LlmResponse(
            text: $text,
            parsed: null,
            model: 'fake-model',
            stopReason: $stopReason,
            usage: new TokenUsage(inputTokens: 1200, outputTokens: 300, cacheReadInputTokens: 800),
            latencyMs: 120,
        );

        return $this;
    }

    /** 次の呼び出しで指定した種類の失敗を起こす。 */
    public function queueFailure(LlmErrorType $type, ?int $retryAfterSeconds = null): self
    {
        $this->queue[] = new LlmException($type, "FakeClient による {$type->label()} の再現", $retryAfterSeconds);

        return $this;
    }

    /** 次の応答を、その機能の既定の内容にする。 */
    public function queueDefaultFor(LlmFeature $feature): self
    {
        $this->queue[] = $this->makeResponse($this->fixtureFor($feature));

        return $this;
    }

    /** @return list<LlmRequest> */
    public function received(): array
    {
        return $this->received;
    }

    public function lastRequest(): ?LlmRequest
    {
        return $this->received === [] ? null : $this->received[count($this->received) - 1];
    }

    public function callCount(): int
    {
        return count($this->received);
    }

    // ---------------------------------------------------------------
    // 既定の応答
    // ---------------------------------------------------------------

    private function defaultResponse(LlmRequest $request): LlmResponse
    {
        return $this->makeResponse($this->fixtureFor($request->feature));
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function makeResponse(array $parsed, ?string $stopReason = 'end_turn'): LlmResponse
    {
        return new LlmResponse(
            text: (string) json_encode($parsed, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            parsed: $parsed,
            model: 'fake-model',
            stopReason: $stopReason,
            // 実際の呼び出しに近い数値を入れておく。コスト集計画面が
            // デモモードでも意味のある表示になるようにするため。
            usage: new TokenUsage(
                inputTokens: 1200,
                outputTokens: 800,
                cacheReadInputTokens: 2800,
                cacheCreationInputTokens: 0,
            ),
            latencyMs: 1450,
        );
    }

    /**
     * 機能ごとの既定データ。実際のスキーマに沿った内容にしてある。
     *
     * @return array<string, mixed>
     */
    private function fixtureFor(LlmFeature $feature): array
    {
        return match ($feature) {
            LlmFeature::VoiceTransform => [
                'record_text' => '入浴時、浴槽をまたぐ際に右足の挙上が不十分であり、腰部を支持して介助を実施。'
                    .'昼食は主食5割程度の摂取。食事中に1回のむせ込みあり。'
                    .'レクリエーション（塗り絵）に参加されたが、約20分で疲労感の訴えがあり休憩された。',
                'family_text' => '本日もお元気にお過ごしでした。入浴では手すりを使いながら、職員がお手伝いして'
                    .'湯船に浸かっていただきました。塗り絵では色を丁寧に塗っておられました。'
                    .'お食事の際に一度むせ込みがございましたので、お迎えの際に職員より詳しくご説明させていただきます。',
                'handover_note' => '食事中にむせ込み1回あり。次回の食事時は形態と姿勢を要確認。'
                    .'入浴は腰部支持での介助を継続。',
                'requires_verbal_contact' => [
                    [
                        'topic' => '食事中のむせ込み',
                        'why' => '頻度や食形態との関係によって意味が変わる事項であり、ご家族が状況を質問できる形で伝える必要があるため',
                        'urgency' => 'same_day',
                    ],
                ],
                'family_excluded' => [],
                'uncertain' => [
                    '「昼ごはん半分くらい」から主食5割と判断しましたが、副菜の摂取量は言及がないため補完していません',
                ],
                'detected_items' => [
                    'meal_staple_rate' => 50,
                    'meal_side_rate' => null,
                    'water_ml' => null,
                    'bathing_type' => 'bath',
                    'incident_suspected' => true,
                ],
            ],

            LlmFeature::RiskDetection => [
                'no_risk_detected' => false,
                'confidence' => 'medium',
                'risks' => [
                    [
                        'category' => 'malnutrition',
                        'severity' => 'high',
                        'source' => 'rule_based',
                        'title' => '1ヶ月で体重が 3.5% 減少',
                        'reason' => '前月 42.6kg から当月 41.1kg へ減少（減少率 3.5%）。3%以上は低栄養リスクの判定基準に該当します。',
                        'evidence' => [
                            ['record_id' => 2891, 'date' => '2026-09-01', 'excerpt' => '体重記録 41.1kg'],
                        ],
                        'suggested_actions' => [
                            'ご家族に、ご自宅での食事量・間食の状況を確認する',
                            '昼食の摂取割合を1週間、毎回記録して推移を確認する',
                        ],
                    ],
                    [
                        'category' => 'fall',
                        'severity' => 'medium',
                        'source' => 'llm_detected',
                        'title' => '「ふらつき」に関する記述が3週間で4件に増加',
                        'reason' => '自由記述に「ふらつき」「足の上がりが浅い」といった記述が増えています。数値のバイタルには異常値は出ていません。',
                        'evidence' => [
                            ['record_id' => 3455, 'date' => '2026-09-02', 'excerpt' => '送迎車の乗降時、ステップでふらつき'],
                        ],
                        'suggested_actions' => [
                            '歩行時の見守りを強化し、送迎車の乗降は2名介助とする',
                        ],
                    ],
                ],
            ],

            LlmFeature::GoalProgress => [
                'period' => ['from' => '2026-08-01', 'to' => '2026-08-31'],
                'overall_summary' => '入浴動作は概ね横ばいで、一部介助での実施が継続しています。'
                    .'一方、8月下旬から活動への参加時間が短くなる記録が複数見られ、水分摂取量も目標を下回る日が増えています。',
                'goals' => [
                    [
                        'goal_id' => 1,
                        'progress_status' => 'unchanged',
                        'comment' => '期間を通じて一部介助での実施が継続しています。8月14日に自力に近い形で成功した記録がありますが、それ以降に同様の記録は見られません。',
                        'evidence' => [
                            ['record_id' => 3301, 'date' => '2026-08-14', 'excerpt' => '手すりを持ち、ほぼ自力で浴槽をまたがれた'],
                        ],
                    ],
                    [
                        'goal_id' => 2,
                        'progress_status' => 'declining',
                        'comment' => '通所は継続されていますが、レクリエーションの途中で休憩される記録が8月下旬に3件あります。',
                        'evidence' => [
                            ['record_id' => 3372, 'date' => '2026-08-21', 'excerpt' => '20分ほどで「疲れた」と話され休憩'],
                        ],
                    ],
                ],
                'next_actions' => [
                    '水分摂取について、ご本人の好まれる飲み物をご家族に確認する',
                    'レクリエーションの参加時間を記録項目に追加し、変化を定量的に追う',
                ],
                'confidence' => 'medium',
            ],

            LlmFeature::Handover => [
                'service_date' => '2026-09-11',
                'handovers' => [
                    [
                        'resident_id' => 7,
                        'priority' => 'high',
                        'summary' => '水分摂取が960mlで4日連続の目標未達。夕方に傾眠傾向が見られました。',
                        'requires_attention' => true,
                    ],
                ],
                'facility_notes' => ['9月15日（月）は祝日のため通常営業です。送迎ルートが変更になります。'],
            ],

            LlmFeature::FamilyReport => [
                'drafts' => [
                    [
                        'tone' => 'standard',
                        'title' => '8月のご様子について',
                        'body' => '8月も週3回、お元気に通所していただきました。入浴では手すりを使いながら、'
                            .'ご自身のペースで浴槽をまたぐ動作に取り組まれています。',
                        'highlights' => ['入浴動作の成功', 'レクでの様子'],
                    ],
                    [
                        'tone' => 'warm',
                        'title' => 'ハナ様の8月',
                        'body' => '暑い8月でしたが、今月も元気にお越しくださいました。'
                            .'14日の入浴で、手すりを持ちながらほぼご自身の力で浴槽をまたがれました。',
                        'highlights' => ['ご本人の言葉', '感情に寄り添う'],
                    ],
                    [
                        'tone' => 'concise',
                        'title' => '8月のご報告',
                        'body' => "8月は13回ご利用いただきました。\n・入浴：手すりを使用し、一部介助で実施。\n・食事：概ね良好にお召し上がりです。",
                        'highlights' => ['箇条書き', '短時間で読める'],
                    ],
                ],
                'cautions' => [],
            ],
        };
    }
}
