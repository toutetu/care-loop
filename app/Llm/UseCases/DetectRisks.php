<?php

namespace App\Llm\UseCases;

use App\Enums\RiskCategory;
use App\Enums\RiskSeverity;
use App\Enums\RiskSource;
use App\Llm\Data\LlmRequest;
use App\Llm\Data\RiskIndicator;
use App\Llm\LlmGateway;
use App\Llm\Prompts\RiskDetectionPrompt;
use App\Llm\Support\PiiMasker;
use App\Llm\Support\RiskIndicatorCalculator;
use App\Models\LlmJob;
use App\Models\Resident;
use App\Models\RiskAssessment;
use App\Models\ServiceRecord;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * F-LLM-02 リスク兆候の抽出。
 *
 * 【ハイブリッド設計】
 *   1. 数値で判定できるリスクを RiskIndicatorCalculator が決定的に算出する
 *   2. その結果をLLMへ渡し、「数値に現れない質的な変化」だけを抽出させる
 *   3. 両者を1つの評価としてまとめ、検出元（source）を区別して保存する
 *
 * 画面では検出元をバッジで表示する。ルールベース由来は再現性があり、
 * LLM由来は要確認という扱いの差を、職員が判断できるようにするため。
 *
 * 【重複の扱い】
 * LLMがルールベースと同じ category を挙げてきた場合は、ルールベース側の
 * 指摘を source = both に変更し、LLM側の重複は保存しない。
 * ルールベース側のほうが具体的な数値を持っており、根拠として強いため。
 */
final class DetectRisks
{
    public function __construct(
        private readonly LlmGateway $gateway,
        private readonly RiskDetectionPrompt $prompt,
        private readonly RiskIndicatorCalculator $calculator,
    ) {}

    /**
     * ルールベースの指標とLLMの指摘をまとめた評価を1件作成して返す。
     */
    public function handle(Resident $resident, CarbonInterface $from, CarbonInterface $to, ?LlmJob $job = null): RiskAssessment
    {
        $indicators = $this->calculator->calculate($resident, $from, $to);

        $records = $resident->serviceRecords()
            ->attended()
            ->inPeriod($from, $to)
            ->orderBy('service_date')
            ->with('notes')
            ->get();

        $masker = $this->buildMasker($resident);

        $result = $this->gateway->send(
            new LlmRequest(
                feature: $this->prompt->feature(),
                model: $this->prompt->feature()->model(),
                systemPrompt: $this->prompt->systemPrompt(),
                userMessage: $this->buildUserMessage($resident, $from, $to, $indicators, $records, $masker),
                maxTokens: $this->prompt->feature()->maxTokens(),
                jsonSchema: $this->prompt->schema(),
            ),
            $this->prompt->schema(),
            $job,
        );

        $result = $masker->unmaskArray($result);

        return $this->persist($resident, $from, $to, $indicators, $result, $job);
    }

    private function buildMasker(Resident $resident): PiiMasker
    {
        $masker = new PiiMasker;

        $masker->register(PiiMasker::RESIDENT, $resident->name);
        $masker->register(PiiMasker::RESIDENT, $resident->name_kana);
        $masker->register(PiiMasker::FACILITY, $resident->facility->name ?? null);

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
     * 算出済みの指標を先に示し、「これと重複する指摘は不要」と伝える。
     * 同じことを二度指摘させてもトークンの無駄になるため。
     *
     * 記録には record_id を明示する。LLMに根拠として引用させるために必要。
     *
     * @param  list<RiskIndicator>  $indicators
     * @param  Collection<int, ServiceRecord>  $records
     */
    private function buildUserMessage(
        Resident $resident,
        CarbonInterface $from,
        CarbonInterface $to,
        array $indicators,
        $records,
        PiiMasker $masker,
    ): string {
        $lines = [
            '# ご利用者の情報',
            sprintf('- 呼称: %s', $masker->mask($resident->name)),
            sprintf('- 年齢: %s', $resident->age !== null ? "{$resident->age}歳" : '不明'),
            sprintf('- 要介護度: %s', $resident->careLevel->name ?? '不明'),
            sprintf('- 既往歴: %s', $resident->medical_history ?? '記載なし'),
            '',
            sprintf('# 対象期間: %s 〜 %s（利用 %d回）', $from->toDateString(), $to->toDateString(), $records->count()),
            '',
            '# すでに算出済みの指標（重複する指摘は不要です）',
        ];

        if ($indicators === []) {
            $lines[] = '- 数値による判定では、基準を外れた項目はありませんでした。';
        } else {
            foreach ($indicators as $indicator) {
                $lines[] = $indicator->summaryLine();
            }
        }

        $lines[] = '';
        $lines[] = '# 期間内の記録（自由記述）';

        $hasNote = false;

        foreach ($records as $record) {
            // 整形済みの記録文があればそれを、なければその日の原文をつないで渡す。
            $note = $record->record_text ?? $record->combinedNoteText();

            if (trim($note) === '') {
                continue;
            }

            $hasNote = true;
            $lines[] = sprintf('[記録 #%d] %s', $record->id, $record->service_date->toDateString());
            $lines[] = (string) $masker->mask($note);
            $lines[] = '';
        }

        if (! $hasNote) {
            $lines[] = '（自由記述の記録がありません。confidence は low としてください）';
        }

        return implode("\n", $lines);
    }

    /**
     * ルールベースの指標とLLMの指摘を1つの評価としてまとめる。
     *
     * @param  list<RiskIndicator>  $indicators
     * @param  array<string, mixed>  $result
     */
    private function persist(
        Resident $resident,
        CarbonInterface $from,
        CarbonInterface $to,
        array $indicators,
        array $result,
        ?LlmJob $job,
    ): RiskAssessment {
        return DB::transaction(function () use ($resident, $from, $to, $indicators, $result, $job): RiskAssessment {
            $llmRisks = is_array($result['risks'] ?? null) ? $result['risks'] : [];

            $assessment = RiskAssessment::create([
                'resident_id' => $resident->id,
                'llm_job_id' => $job?->id,
                'period_from' => $from,
                'period_to' => $to,
                'assessed_at' => now(),
                'no_risk_detected' => $indicators === [] && $llmRisks === [],
                'confidence' => is_string($result['confidence'] ?? null) ? $result['confidence'] : null,
            ]);

            // LLM が挙げた category を先に把握し、ルールベース側と重なるものを both にする
            $llmCategories = [];

            foreach ($llmRisks as $risk) {
                if (is_array($risk) && is_string($risk['category'] ?? null)) {
                    $llmCategories[] = $risk['category'];
                }
            }

            $ruleCategories = [];

            foreach ($indicators as $indicator) {
                $overlaps = in_array($indicator->category->value, $llmCategories, true);
                $ruleCategories[] = $indicator->category->value;

                $assessment->findings()->create(
                    $indicator->toFindingAttributes($overlaps ? RiskSource::Both : RiskSource::RuleBased)
                );
            }

            foreach ($llmRisks as $risk) {
                if (! is_array($risk)) {
                    continue;
                }

                $category = RiskCategory::tryFrom((string) ($risk['category'] ?? ''));
                $severity = RiskSeverity::tryFrom((string) ($risk['severity'] ?? ''));

                if ($category === null || $severity === null) {
                    continue;
                }

                // ルールベースが同じ種別をすでに挙げている場合、そちらのほうが
                // 具体的な数値を根拠に持つため、LLM側の重複は保存しない
                if (in_array($category->value, $ruleCategories, true)) {
                    continue;
                }

                $assessment->findings()->create([
                    'category' => $category,
                    'severity' => $severity,
                    'source' => RiskSource::LlmDetected,
                    'title' => (string) ($risk['title'] ?? ''),
                    'reason' => (string) ($risk['reason'] ?? ''),
                    'evidence' => is_array($risk['evidence'] ?? null) ? $risk['evidence'] : [],
                    'suggested_actions' => is_array($risk['suggested_actions'] ?? null) ? $risk['suggested_actions'] : [],
                ]);
            }

            return $assessment->load('findings');
        });
    }
}
