<?php

namespace App\Llm\UseCases;

use App\Enums\ProgressStatus;
use App\Llm\Data\LlmRequest;
use App\Llm\LlmGateway;
use App\Llm\Prompts\GoalProgressPrompt;
use App\Llm\Support\PeriodStatistics;
use App\Llm\Support\PiiMasker;
use App\Models\CarePlan;
use App\Models\CarePlanGoal;
use App\Models\GoalProgressReport;
use App\Models\LlmJob;
use App\Models\Resident;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * F-LLM-01 通所介護計画 目標進捗要約。
 *
 * 職務経歴書に記載する「業務データを基にプロジェクト進捗の要約を行う機能」に
 * 対応する。介護ドメインでは、通所介護計画書の短期目標に対する達成状況の
 * 振り返り（モニタリング）がそれにあたる。
 *
 * 【数値は渡す、数えさせない】
 * 目標の達成回数などの集計は PeriodStatistics が行い、結果をプロンプトに含める。
 * LLMには、その数値と自由記述を踏まえた評価だけを求める。
 *
 * 【評価されなかった目標を放置しない】
 * LLMが触れなかった目標には insufficient_data の評価を自動で作る。
 * 一部の目標が抜け落ちたモニタリング記録は、記録として不完全なため。
 */
final class SummarizeGoalProgress
{
    public function __construct(
        private readonly LlmGateway $gateway,
        private readonly GoalProgressPrompt $prompt,
        private readonly PeriodStatistics $statistics,
    ) {}

    public function handle(
        Resident $resident,
        CarePlan $plan,
        CarbonInterface $from,
        CarbonInterface $to,
        ?LlmJob $job = null,
    ): GoalProgressReport {
        $goals = $plan->goals()->get();
        $masker = $this->buildMasker($resident);

        $result = $this->gateway->send(
            new LlmRequest(
                feature: $this->prompt->feature(),
                model: $this->prompt->feature()->model(),
                systemPrompt: $this->prompt->systemPrompt(),
                userMessage: $this->buildUserMessage($resident, $plan, $goals, $from, $to, $masker),
                maxTokens: $this->prompt->feature()->maxTokens(),
                jsonSchema: $this->prompt->schema(),
            ),
            $this->prompt->schema(),
            $job,
        );

        $result = $masker->unmaskArray($result);

        return $this->persist($resident, $plan, $goals, $from, $to, $result, $job);
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
     * @param  Collection<int, CarePlanGoal>  $goals
     */
    private function buildUserMessage(
        Resident $resident,
        CarePlan $plan,
        $goals,
        CarbonInterface $from,
        CarbonInterface $to,
        PiiMasker $masker,
    ): string {
        $lines = [
            '# ご利用者の情報',
            sprintf('- 呼称: %s', $masker->mask($resident->name)),
            sprintf('- 年齢: %s', $resident->age !== null ? "{$resident->age}歳" : '不明'),
            sprintf('- 要介護度: %s', $resident->careLevel->name ?? '不明'),
            '',
            '# 通所介護計画書',
            sprintf('- 計画期間: %s 〜 %s', $plan->period_from->toDateString(), $plan->period_to->toDateString()),
            sprintf('- 長期目標: %s', (string) $masker->mask($plan->long_term_goal)),
            '',
            '# 短期目標（goal_id をそのまま使ってください）',
        ];

        foreach ($goals as $goal) {
            $lines[] = sprintf('- goal_id %d: %s', $goal->id, (string) $masker->mask($goal->goal_text));
        }

        $lines[] = '';
        $lines[] = sprintf('# 対象期間: %s 〜 %s', $from->toDateString(), $to->toDateString());
        $lines[] = '';
        $lines[] = '# 集計値（算出済みです。数え直す必要はありません）';

        foreach ($this->statistics->lines($resident, $from, $to) as $line) {
            $lines[] = $line;
        }

        $lines[] = '';
        $lines[] = '# 対象期間の記録（自由記述）';

        $records = $resident->serviceRecords()
            ->attended()
            ->inPeriod($from, $to)
            ->orderBy('service_date')
            ->get();

        $hasNote = false;

        foreach ($records as $record) {
            $note = $record->record_text ?? $record->raw_note;

            if ($note === null || trim($note) === '') {
                continue;
            }

            $hasNote = true;
            $lines[] = sprintf('[記録 #%d] %s', $record->id, $record->service_date->toDateString());
            $lines[] = (string) $masker->mask($note);
            $lines[] = '';
        }

        if (! $hasNote) {
            $lines[] = '（自由記述の記録がありません。判断できない目標は insufficient_data としてください）';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  Collection<int, CarePlanGoal>  $goals
     * @param  array<string, mixed>  $result
     */
    private function persist(
        Resident $resident,
        CarePlan $plan,
        $goals,
        CarbonInterface $from,
        CarbonInterface $to,
        array $result,
        ?LlmJob $job,
    ): GoalProgressReport {
        return DB::transaction(function () use ($resident, $plan, $goals, $from, $to, $result, $job): GoalProgressReport {
            $report = GoalProgressReport::create([
                'resident_id' => $resident->id,
                'care_plan_id' => $plan->id,
                'llm_job_id' => $job?->id,
                'period_from' => $from,
                'period_to' => $to,
                'overall_summary' => is_string($result['overall_summary'] ?? null) ? $result['overall_summary'] : null,
                'next_actions' => is_array($result['next_actions'] ?? null) ? array_values($result['next_actions']) : [],
                'confidence' => is_string($result['confidence'] ?? null) ? $result['confidence'] : null,
            ]);

            $validIds = $goals->pluck('id')->all();
            $evaluated = [];

            foreach (is_array($result['goals'] ?? null) ? $result['goals'] : [] as $goal) {
                if (! is_array($goal)) {
                    continue;
                }

                $goalId = is_int($goal['goal_id'] ?? null) ? $goal['goal_id'] : null;
                $status = ProgressStatus::tryFrom((string) ($goal['progress_status'] ?? ''));

                // 計画書に存在しない goal_id は捨てる。
                // 存在しない目標への評価を保存すると、記録として意味をなさない。
                if ($goalId === null || $status === null || ! in_array($goalId, $validIds, true)) {
                    continue;
                }

                if (in_array($goalId, $evaluated, true)) {
                    continue;
                }

                $evaluated[] = $goalId;

                $report->items()->create([
                    'care_plan_goal_id' => $goalId,
                    'progress_status' => $status,
                    'comment' => is_string($goal['comment'] ?? null) ? $goal['comment'] : null,
                    'evidence' => is_array($goal['evidence'] ?? null) ? array_values($goal['evidence']) : [],
                ]);
            }

            // 触れられなかった目標は「判断材料が不足」として残す。
            // 一部の目標が抜け落ちたモニタリング記録は不完全であるため。
            foreach ($goals as $goal) {
                if (in_array($goal->id, $evaluated, true)) {
                    continue;
                }

                $report->items()->create([
                    'care_plan_goal_id' => $goal->id,
                    'progress_status' => ProgressStatus::InsufficientData,
                    'comment' => 'この目標に関する評価が得られませんでした。記録を確認のうえ、手動で評価してください。',
                    'evidence' => [],
                ]);
            }

            return $report->load('items');
        });
    }
}
