<?php

namespace App\Console\Commands;

use App\Enums\LlmFeature;
use App\Llm\Contracts\LlmClient;
use App\Llm\Data\LlmRequest;
use App\Llm\Exceptions\LlmException;
use App\Models\LlmRequest as LlmRequestRecord;
use Illuminate\Console\Command;

/**
 * Claude API への疎通を1回だけ確認する。
 *
 * 自動テストはすべて FakeClient 経由で動くため、SDKへの引数の渡し方が
 * 実際に通るかは検証できない。実APIへ1回だけ投げて、
 * 構造化出力・プロンプトキャッシュ・usage の取得が想定どおり動くかを確かめる。
 *
 * 最小構成（maxTokens 200・短いプロンプト）で呼ぶため、費用は1回あたり
 * 0.1円未満に収まる。
 */
class LlmPingCommand extends Command
{
    protected $signature = 'llm:ping';

    protected $description = 'Claude API への疎通を1回だけ確認します（実際にAPIを呼び出します）';

    public function handle(LlmClient $client): int
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

        $request = new LlmRequest(
            feature: LlmFeature::VoiceTransform,
            model: $model,
            systemPrompt: 'あなたは通所介護の記録を整形するアシスタントです。指定されたJSON形式のみを出力してください。',
            userMessage: '次の記録を整形してください：「入浴時にふらつきあり」',
            maxTokens: 200,
            jsonSchema: [
                'type' => 'object',
                'required' => ['record_text'],
                'properties' => [
                    'record_text' => ['type' => 'string'],
                ],
            ],
        );

        try {
            $response = $client->send($request);
        } catch (LlmException $e) {
            $this->error("  失敗: {$e->errorType->label()}");
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

        $this->info('  疎通に成功しました');
        $this->line('');
        $this->table(
            ['項目', '値'],
            [
                ['応答モデル', $response->model],
                ['stop_reason', $response->stopReason ?? '-'],
                ['構造化出力', $response->hasParsedContent() ? '取得できました' : '本文のみ（要確認）'],
                ['入力トークン', number_format($response->usage->inputTokens)],
                ['出力トークン', number_format($response->usage->outputTokens)],
                ['キャッシュ読取', number_format($response->usage->cacheReadInputTokens)],
                ['応答時間', "{$response->latencyMs} ms"],
                ['推定コスト', '$'.number_format($cost, 6).'（約'.number_format($cost * 150, 2).'円）'],
            ],
        );

        if ($response->hasParsedContent()) {
            $this->line('  <options=bold>構造化出力の中身</>');
            $this->line('  '.(string) json_encode($response->parsed, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } else {
            $this->warn('  構造化出力が取得できませんでした。本文は次のとおりです。');
            $this->line('  '.$response->text);
        }

        $this->line('');

        return self::SUCCESS;
    }
}
