<?php

namespace App\Llm\UseCases;

use App\Enums\BathingType;
use App\Llm\Data\LlmRequest;
use App\Llm\LlmGateway;
use App\Llm\Prompts\VoiceTransformPrompt;
use App\Llm\Support\PiiMasker;
use App\Models\LlmJob;
use App\Models\ServiceRecord;
use App\Models\VerbalContactTask;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * F-LLM-05 音声入力の三面変換を実行し、結果を記録へ反映する。
 *
 * 音声入力された原文（raw_note）から、記録用・ご家族向け・申し送り用の
 * 3つの文体と、構造化データを一度に生成する。
 *
 * 【処理の順序】
 *   1. 個人情報をプレースホルダへ置換する（送信前）
 *   2. LLMへ送信し、検証済みの構造化データを受け取る
 *   3. プレースホルダを実名へ戻す（受信後）
 *   4. 記録へ反映し、必要な口頭連絡タスクを発行する
 *
 * 【原文は書き換えない】
 * raw_note には手を触れない。AIが何を変えたのかを後から検証できない記録は、
 * 法定文書として使えないため。
 */
final class TransformVoiceNote
{
    public function __construct(
        private readonly LlmGateway $gateway,
        private readonly VoiceTransformPrompt $prompt,
    ) {}

    /**
     * @return array<string, mixed> 検証済み・実名復元済みの結果
     */
    public function handle(ServiceRecord $record, ?LlmJob $job = null): array
    {
        $rawNote = trim((string) $record->raw_note);

        if ($rawNote === '') {
            throw new RuntimeException('音声入力された原文がありません。');
        }

        $masker = $this->buildMasker($record);

        $result = $this->gateway->send(
            new LlmRequest(
                feature: $this->prompt->feature(),
                model: $this->prompt->feature()->model(),
                systemPrompt: $this->prompt->systemPrompt(),
                userMessage: $this->buildUserMessage($record, $rawNote, $masker),
                maxTokens: $this->prompt->feature()->maxTokens(),
                jsonSchema: $this->prompt->schema(),
            ),
            $this->prompt->schema(),
            $job,
        );

        $result = $masker->unmaskArray($result);

        $this->apply($record, $result, $job);

        return $result;
    }

    /**
     * 置換対象を登録する。
     *
     * 同じ事業所の他のご利用者も登録しておく。職員が自由記述に他の方のお名前を
     * 書いてしまった場合でも、外部へ実名が出ないようにするため。
     * （プロンプト側でも「他のご利用者に触れない」よう指示しているが、
     * 送信前に置換しておけば、指示が守られなくても実名は漏れない）
     */
    private function buildMasker(ServiceRecord $record): PiiMasker
    {
        $masker = new PiiMasker;
        $resident = $record->resident;

        $masker->register(PiiMasker::RESIDENT, $resident->name);
        $masker->register(PiiMasker::RESIDENT, $resident->name_kana);
        $masker->register(PiiMasker::FAMILY, $resident->family_contact);
        $masker->register(PiiMasker::STAFF, $record->recorder?->name);
        $masker->register(PiiMasker::FACILITY, $resident->facility?->name);

        $facility = $resident->facility;

        if ($facility !== null) {
            foreach ($facility->residents()->whereKeyNot($resident->getKey())->get() as $other) {
                $masker->register(PiiMasker::RESIDENT, $other->name);
            }

            foreach ($facility->users()->get() as $staff) {
                $masker->register(PiiMasker::STAFF, $staff->name);
            }
        }

        return $masker;
    }

    /**
     * 送信本文を組み立てる。
     *
     * 【生年月日ではなく年齢を送る】
     * 生年月日は個人を特定しうるが、年齢は判断に必要な文脈である。
     * 必要な情報だけを、特定できない粒度にして送る（要件定義 7.2節）。
     *
     * 【既往歴と食形態は送る】
     * 嚥下障害の既往があるかどうかで、むせ込み1回の重みは変わる。
     * 氏名を伏せたうえで健康状態を送ることが、この設計の前提である。
     */
    private function buildUserMessage(ServiceRecord $record, string $rawNote, PiiMasker $masker): string
    {
        $resident = $record->resident;

        $lines = [
            '# ご利用者の情報',
            sprintf('- 呼称: %s', $masker->mask($resident->name)),
            sprintf('- 年齢: %s', $resident->age !== null ? "{$resident->age}歳" : '不明'),
            sprintf('- 要介護度: %s', $resident->careLevel->name ?? '不明'),
            sprintf('- 既往歴: %s', $resident->medical_history ?? '記載なし'),
        ];

        $mealForm = $record->mealRecords->first()?->meal_form;

        if ($mealForm !== null) {
            $lines[] = sprintf('- 食形態: %s', $mealForm);
        }

        $lines[] = '';
        $lines[] = '# 当日の記録';
        $lines[] = sprintf('- 日付: %s', $record->service_date->format('Y年n月j日'));

        foreach ($record->vitalSigns as $vital) {
            $lines[] = sprintf(
                '- バイタル（%s）: 体温 %s / 血圧 %s / SpO2 %s',
                $vital->measured_at->format('H:i'),
                $vital->temperature !== null ? "{$vital->temperature}℃" : '未測定',
                $vital->systolic_bp !== null ? "{$vital->systolic_bp}/{$vital->diastolic_bp}" : '未測定',
                $vital->spo2 !== null ? "{$vital->spo2}%" : '未測定',
            );
        }

        if ($record->total_water_ml !== null) {
            $lines[] = sprintf('- 水分摂取量（入力済み）: %dml', $record->total_water_ml);
        }

        $lines[] = '';
        $lines[] = '# 職員が音声入力した原文';
        $lines[] = (string) $masker->mask($rawNote);

        return implode("\n", $lines);
    }

    /**
     * 結果を記録へ反映する。
     *
     * @param  array<string, mixed>  $result
     */
    private function apply(ServiceRecord $record, array $result, ?LlmJob $job): void
    {
        DB::transaction(function () use ($record, $result, $job): void {
            $record->forceFill([
                'record_text' => $this->stringOf($result, 'record_text'),
                'family_text' => $this->stringOf($result, 'family_text'),
                'handover_note' => $this->stringOf($result, 'handover_note'),
                'llm_job_id' => $job?->id,
                // 職員が内容を確認するまで確定しない。AIの出力がそのまま
                // 法定文書になることはない（Human-in-the-Loop）。
                'confirmed_at' => null,
                'record_text_edited_by_human' => false,
                'family_text_edited_by_human' => false,
            ])->save();

            $this->createVerbalContactTasks($record, $result);
            $this->applyDetectedItems($record, $result);
        });
    }

    /**
     * 口頭連絡タスクを発行する（F-20）。
     *
     * ご家族向け文書に書いたうえで、さらに口頭での補足が必要な事項について
     * 送迎担当者のタスクを作る。「連絡帳に書いてあるから伝えた」で
     * 終わらせないための仕組み。
     *
     * @param  array<string, mixed>  $result
     */
    private function createVerbalContactTasks(ServiceRecord $record, array $result): void
    {
        $items = $result['requires_verbal_contact'] ?? [];

        if (! is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if (! is_array($item) || ! isset($item['topic'])) {
                continue;
            }

            VerbalContactTask::create([
                'resident_id' => $record->resident_id,
                'service_record_id' => $record->id,
                'topic' => (string) $item['topic'],
                'reason' => (string) ($item['why'] ?? ''),
                'urgency' => in_array($item['urgency'] ?? null, ['same_day', 'next_visit'], true)
                    ? $item['urgency']
                    : 'same_day',
                'source' => 'llm',
            ]);
        }
    }

    /**
     * 音声から抽出された数値をフォームへ反映する。
     *
     * 【職員が入力済みの値は上書きしない】
     * AIの抽出より、人が実際に見て入力した値のほうが確かである。
     * 空欄のときだけ埋める。
     *
     * @param  array<string, mixed>  $result
     */
    private function applyDetectedItems(ServiceRecord $record, array $result): void
    {
        $detected = $result['detected_items'] ?? null;

        if (! is_array($detected)) {
            return;
        }

        $updates = [];

        if ($record->total_water_ml === null && is_int($detected['water_ml'] ?? null)) {
            $updates['total_water_ml'] = $detected['water_ml'];
        }

        $bathingType = is_string($detected['bathing_type'] ?? null)
            ? BathingType::tryFrom($detected['bathing_type'])
            : null;

        if ($record->bathing_type === null && $bathingType !== null) {
            $updates['bathing_type'] = $bathingType;
        }

        if ($updates !== []) {
            $record->forceFill($updates)->save();
        }

        $staple = $detected['meal_staple_rate'] ?? null;
        $side = $detected['meal_side_rate'] ?? null;

        if (! is_int($staple) && ! is_int($side)) {
            return;
        }

        $meal = $record->mealRecords()->firstOrNew(['meal_type' => 'lunch']);

        if ($meal->staple_rate === null && is_int($staple)) {
            $meal->staple_rate = $staple;
        }

        if ($meal->side_rate === null && is_int($side)) {
            $meal->side_rate = $side;
        }

        $meal->service_record_id = $record->id;
        $meal->save();
    }

    /** @param array<string, mixed> $result */
    private function stringOf(array $result, string $key): ?string
    {
        $value = $result[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}
