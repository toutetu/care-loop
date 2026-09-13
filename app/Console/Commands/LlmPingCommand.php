<?php

namespace App\Console\Commands;

use App\Enums\LlmFeature;
use App\Llm\Contracts\LlmClient;
use App\Llm\Data\LlmRequest;
use App\Llm\Exceptions\LlmException;
use App\Llm\Support\ResponseValidator;
use App\Models\LlmRequest as LlmRequestRecord;
use Illuminate\Console\Command;

/**
 * Claude API への疎通を1回だけ確認する。
 *
 * 自動テストはすべて FakeClient 経由で動くため、SDKへの引数の渡し方が
 * 実際に通るかは検証できない。実APIへ1回だけ投げて、
 * 送信・構造化出力・スキーマ検証・usage の取得までが通ることを確かめる。
 *
 * 検証は本番と同じ ResponseValidator で行う。ここだけ別の方法で確認しても、
 * 本番の経路が動く保証にならないため。
 *
 * 費用は1回あたり1円未満。
 */
class LlmPingCommand extends Command
{
    protected $signature = 'llm:ping';

    protected $description = 'Claude API への疎通を1回だけ確認します（実際にAPIを呼び出します）';

    public function handle(LlmClient $client, ResponseValidator $validator): int
    {
        $this->line('');
        $this->line("  ドライバ : <options=bold>{$client->name()}</>");

        if ($client->name() === 'fake') {
            $this->warn('  FakeClient が使われています。実APIは呼び出されません。');
            $this->line('  .env の ANTHROPIC_API_KEY と LLM_DRIVER を確認してください。');
            $this->line('');

            return self::FAILURE;
        }

        $model = (string) config('llm.default_model');
        $this->line("  モデル   : <options=bold>{$model}</>");
        $this->line('');

        $schema = [
            'type' => 'object',
            'required' => ['record_text'],
            'properties' => [
                'record_text' => ['type' => 'string'],
            ],
        ];

        $request = new LlmRequest(
            feature: LlmFeature::VoiceTransform,
            model: $model,
            systemPrompt: 'あなたは通所介護の記録を整形するアシスタントです。指定されたJSON形式のみを出力してください。',
            userMessage: '次の記録を整形してください：「入浴時にふらつきあり」',
            // Claude Opus 5 は思考がデフォルトで有効なため、
            // 上限が小さいと思考だけで使い切って本文が空になる。
            maxTokens: 1500,
            jsonSchema: $schema,
        );

        try {
            $response = $client->send($request);
        } catch (LlmException $e) {
            $this->error("  送信に失敗しました: {$e->errorType->label()}");
            $this->line("  {$e->getMessage()}");
            $this->line('');
            $this->line("  画面表示用の文言: {$e->userMessage()}");
            $this->line('');

            return self::FAILURE;
        }

        $cost = LlmRequestRecord::calculateCostUsd(
            $model,
            $response->usage->inputTokens,
            $response->usage->outputTokens,
        );

        // 本番と同じ検証器を通す
        $validationResult = '検証OK';
        $parsed = null;

        try {
            $parsed = $validator->validate($response, $schema);
        } catch (LlmException $e) {
            $validationResult = "失敗（{$e->errorType->label()}）";
        }

        $this->info('  疎通に成功しました');
        $this->line('');
        $this->table(
            ['項目', '値'],
            [
                ['応答モデル', $response->model],
                ['stop_reason', $response->stopReason ?? '-'],
                ['スキーマ検証', $validationResult],
                ['入力トークン', number_format($response->usage->inputTokens)],
                ['出力トークン', number_format($response->usage->outputTokens).'（思考を含む）'],
                ['キャッシュ読取', number_format($response->usage->cacheReadInputTokens)],
                ['キャッシュ作成', number_format($response->usage->cacheCreationInputTokens)],
                ['応答時間', "{$response->latencyMs} ms"],
                ['推定コスト', '$'.number_format($cost, 6).'（約'.number_format($cost * 150, 2).'円）'],
            ],
        );

        if ($parsed !== null) {
            $this->line('  <options=bold>検証を通った構造化データ</>');
            $this->line('  '.(string) json_encode($parsed, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } else {
            $this->warn('  スキーマ検証に失敗しました。応答本文は次のとおりです。');
            $this->line('  '.$response->text);
        }

        if ($response->usage->cacheCreationInputTokens === 0 && $response->usage->cacheReadInputTokens === 0) {
            $this->line('');
            $this->comment('  ※ このプロンプトは短いため、キャッシュの最小長に達していません。');
            $this->comment('    実際の機能ではシステムプロンプトが長いためキャッシュが働きます。');
        }

        $this->line('');

        return self::SUCCESS;
    }
}
