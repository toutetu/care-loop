<?php

namespace App\Llm\Support;

use App\Enums\RiskCategory;
use App\Enums\RiskSeverity;
use App\Llm\Data\RiskIndicator;
use App\Models\IncidentReport;
use App\Models\Resident;
use App\Models\ServiceRecord;
use App\Models\VitalSign;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * 数値で判定できるリスクを、LLMを使わずに算出する。
 *
 * 【なぜLLMに任せないのか】
 * 体重減少率や連続未達日数は算術である。LLMに計算させると、
 *   - 同じ入力でも結果が揺れる（再現性がない）
 *   - なぜその数値になったのかを検証できない
 *   - 呼び出しのたびに課金される
 * という3つの問題が同時に発生する。
 *
 * ここで算出した指標は RiskSource::RuleBased として記録し、
 * 画面でも「ルールベース検出」と明示する。職員が信頼度を判断できるようにするため。
 *
 * LLMの役割は、ここで拾えない質的な変化
 * （「ふらつきの記述が増えている」「レクへの参加意欲の低下」）に限定する。
 *
 * 【しきい値は設定ファイルから読む】
 * 事業所ごとの運用差や、根拠の見直しに対応できるようにするため
 * （config/careloop.php）。コードに数値を埋め込まない。
 */
final class RiskIndicatorCalculator
{
    /**
     * @return list<RiskIndicator>
     */
    public function calculate(Resident $resident, CarbonInterface $from, CarbonInterface $to): array
    {
        $records = $resident->serviceRecords()
            ->attended()
            ->inPeriod($from, $to)
            ->with(['mealRecords', 'vitalSigns'])
            ->orderBy('service_date')
            ->get();

        return array_values(array_filter([
            $this->weightLoss($resident, $from, $to),
            $this->dehydration($records),
            $this->lowMealIntake($records),
            $this->fever($records),
            $this->bloodPressure($records),
            $this->lowOxygenSaturation($records),
            $this->repeatedIncidents($resident),
        ]));
    }

    /**
     * 低栄養：1ヶ月で3%以上の体重減少。
     *
     * 介護現場で広く使われている栄養スクリーニングの目安に基づく。
     */
    private function weightLoss(Resident $resident, CarbonInterface $from, CarbonInterface $to): ?RiskIndicator
    {
        $latest = $resident->weightRecords()
            ->whereBetween('measured_on', [$from, $to])
            ->latest('measured_on')
            ->first();

        if ($latest === null) {
            return null;
        }

        $rate = $latest->lossRateFromPrevious();
        $threshold = (float) config('careloop.risk_thresholds.weight_loss_rate');

        if ($rate === null || $rate < $threshold) {
            return null;
        }

        $previous = $resident->weightRecords()
            ->where('measured_on', '<', $latest->measured_on)
            ->latest('measured_on')
            ->first();

        return new RiskIndicator(
            category: RiskCategory::Malnutrition,
            severity: RiskSeverity::High,
            title: sprintf('1ヶ月で体重が %.1f%% 減少', $rate * 100),
            reason: sprintf(
                '前回 %.1fkg から今回 %.1fkg へ減少（減少率 %.1f%%）。%.0f%%以上の減少は低栄養リスクの判定基準に該当します。',
                $previous->weight_kg ?? 0,
                $latest->weight_kg,
                $rate * 100,
                $threshold * 100,
            ),
            evidence: [[
                'record_id' => $latest->id,
                'date' => $latest->measured_on->toDateString(),
                'excerpt' => sprintf('体重記録 %.1fkg', $latest->weight_kg),
            ]],
            suggestedActions: [
                'ご家族に、ご自宅での食事量・間食の状況を確認する',
                '昼食の摂取割合を1週間、毎回記録して推移を確認する',
                '管理栄養士への相談を検討する',
            ],
        );
    }

    /**
     * 脱水：目標水分量を下回る日が連続したとき。
     *
     * 過去の一時的な落ち込みではなく「今も続いているか」を見たいので、
     * 直近から遡って途切れるまでの連続日数で判定する。
     *
     * @param  Collection<int, ServiceRecord>  $records
     */
    private function dehydration(Collection $records): ?RiskIndicator
    {
        $target = (int) config('careloop.risk_thresholds.daily_water_ml');
        $needed = (int) config('careloop.risk_thresholds.low_water_consecutive_days');

        $measured = $records->filter(fn (ServiceRecord $r): bool => $r->total_water_ml !== null)->values();

        $streak = [];

        foreach ($measured->reverse() as $record) {
            if ($record->total_water_ml >= $target) {
                break;
            }

            $streak[] = $record;
        }

        if (count($streak) < $needed) {
            return null;
        }

        $streak = array_reverse($streak);

        return new RiskIndicator(
            category: RiskCategory::Dehydration,
            severity: RiskSeverity::Medium,
            title: sprintf('水分摂取量が %d日連続で目標を下回る', count($streak)),
            reason: sprintf(
                '直近%d回の利用で水分摂取量が %s と、目標の %dml を下回っています。',
                count($streak),
                implode(' / ', array_map(
                    static fn (ServiceRecord $r): string => "{$r->total_water_ml}ml",
                    $streak,
                )),
                $target,
            ),
            evidence: array_map(
                static fn (ServiceRecord $r): array => [
                    'record_id' => (int) $r->id,
                    'date' => $r->service_date->toDateString(),
                    'excerpt' => "水分摂取量 {$r->total_water_ml}ml",
                ],
                $streak,
            ),
            suggestedActions: [
                '好まれる飲み物（ゼリー飲料・果汁等）への変更を検討する',
                '提供のタイミングを増やし、1回量を減らす',
            ],
        );
    }

    /**
     * 低栄養：直近の利用日のうち、摂取率が低い日が一定数を超えたとき。
     *
     * @param  Collection<int, ServiceRecord>  $records
     */
    private function lowMealIntake(Collection $records): ?RiskIndicator
    {
        $needed = (int) config('careloop.risk_thresholds.meal_low_days_per_week');

        $recent = $records->reverse()->take(7)->reverse();

        $lowDays = $recent->filter(
            fn (ServiceRecord $r): bool => $r->mealRecords->contains(fn ($meal): bool => $meal->isLowIntake())
        )->values();

        if ($lowDays->count() < $needed) {
            return null;
        }

        return new RiskIndicator(
            category: RiskCategory::Malnutrition,
            severity: RiskSeverity::Medium,
            title: sprintf('直近%d回の利用中 %d回で食事摂取量が低下', $recent->count(), $lowDays->count()),
            reason: sprintf(
                '主食または副菜の摂取割合が %d%% 未満の日が %d回ありました。',
                (int) config('careloop.risk_thresholds.meal_rate_low'),
                $lowDays->count(),
            ),
            evidence: array_values($lowDays->map(static fn (ServiceRecord $r): array => [
                'record_id' => (int) $r->id,
                'date' => $r->service_date->toDateString(),
                'excerpt' => '食事摂取量の低下',
            ])->all()),
            suggestedActions: [
                '食形態と姿勢を見直す',
                '嗜好を確認し、献立の相談を行う',
            ],
        );
    }

    /**
     * @param  Collection<int, ServiceRecord>  $records
     */
    private function fever(Collection $records): ?RiskIndicator
    {
        $threshold = (float) config('careloop.risk_thresholds.temperature_high');

        $hits = $this->vitalHits(
            $records,
            fn ($vital): bool => $vital->temperature !== null && $vital->temperature >= $threshold,
            fn ($vital): string => "体温 {$vital->temperature}℃",
        );

        if ($hits === []) {
            return null;
        }

        return new RiskIndicator(
            category: RiskCategory::Infection,
            severity: RiskSeverity::Medium,
            title: sprintf('%.1f℃ 以上の発熱が %d回', $threshold, count($hits)),
            reason: sprintf('期間内に %.1f℃ 以上の体温が %d回記録されています。', $threshold, count($hits)),
            evidence: $hits,
            suggestedActions: [
                '再検温し、他の症状（咳・下痢・食欲低下）の有無を確認する',
                'ご家族へ当日の体調をお伝えする',
            ],
        );
    }

    /**
     * @param  Collection<int, ServiceRecord>  $records
     */
    private function bloodPressure(Collection $records): ?RiskIndicator
    {
        $high = (int) config('careloop.risk_thresholds.systolic_bp_high');
        $low = (int) config('careloop.risk_thresholds.systolic_bp_low');

        $hits = $this->vitalHits(
            $records,
            fn ($vital): bool => $vital->systolic_bp !== null
                && ($vital->systolic_bp >= $high || $vital->systolic_bp <= $low),
            fn ($vital): string => "収縮期血圧 {$vital->systolic_bp}mmHg",
        );

        if ($hits === []) {
            return null;
        }

        return new RiskIndicator(
            category: RiskCategory::Other,
            severity: RiskSeverity::High,
            title: sprintf('血圧が基準の範囲を外れた記録が %d回', count($hits)),
            reason: sprintf('収縮期血圧が %dmmHg 以上または %dmmHg 以下の記録があります。', $high, $low),
            evidence: $hits,
            suggestedActions: [
                '時間をおいて再測定する',
                'ご本人の自覚症状（頭痛・めまい・気分不快）を確認する',
            ],
        );
    }

    /**
     * @param  Collection<int, ServiceRecord>  $records
     */
    private function lowOxygenSaturation(Collection $records): ?RiskIndicator
    {
        $threshold = (int) config('careloop.risk_thresholds.spo2_low');

        $hits = $this->vitalHits(
            $records,
            fn ($vital): bool => $vital->spo2 !== null && $vital->spo2 <= $threshold,
            fn ($vital): string => "SpO2 {$vital->spo2}%",
        );

        if ($hits === []) {
            return null;
        }

        return new RiskIndicator(
            category: RiskCategory::Other,
            severity: RiskSeverity::High,
            title: sprintf('SpO2 が %d%% 以下の記録が %d回', $threshold, count($hits)),
            reason: sprintf('経皮的動脈血酸素飽和度が %d%% 以下の記録があります。', $threshold),
            evidence: $hits,
            suggestedActions: [
                '測定条件（手指の冷え・体動）を確認して再測定する',
                '呼吸状態と顔色を確認する',
            ],
        );
    }

    /**
     * 転倒：直近一定期間のヒヤリハット・事故の件数。
     */
    private function repeatedIncidents(Resident $resident): ?RiskIndicator
    {
        $needed = (int) config('careloop.risk_thresholds.incident_count');
        $months = (int) config('careloop.risk_thresholds.incident_months');

        $incidents = $resident->incidentReports()->recent($months)->latest('occurred_at')->get();

        if ($incidents->count() < $needed) {
            return null;
        }

        return new RiskIndicator(
            category: RiskCategory::Fall,
            severity: RiskSeverity::High,
            title: sprintf('直近%dヶ月でヒヤリハット・事故が %d件', $months, $incidents->count()),
            reason: sprintf('%d件以上は転倒リスクの判定基準に該当します。', $needed),
            evidence: array_values($incidents->take(5)->map(static fn (IncidentReport $incident): array => [
                'record_id' => (int) $incident->id,
                'date' => $incident->occurred_at->toDateString(),
                'excerpt' => mb_substr($incident->description, 0, 60),
            ])->all()),
            suggestedActions: [
                '歩行時の見守りを強化する',
                '機能訓練指導員に下肢筋力の再評価を依頼する',
                '居室・トイレまでの動線に危険箇所がないか確認する',
            ],
        );
    }

    /**
     * バイタルの条件に合致した記録を根拠の形で集める。
     *
     * 未測定（null）は条件に含めない。測っていないことと、
     * 基準内だったことを混同しないため。
     *
     * @param  Collection<int, ServiceRecord>  $records
     * @param  callable(VitalSign): bool  $matches
     * @param  callable(VitalSign): string  $describe
     * @return list<array{record_id: int|null, date: string, excerpt: string}>
     */
    private function vitalHits(Collection $records, callable $matches, callable $describe): array
    {
        $hits = [];

        foreach ($records as $record) {
            foreach ($record->vitalSigns as $vital) {
                if ($matches($vital)) {
                    $hits[] = [
                        'record_id' => $record->id,
                        'date' => $record->service_date->toDateString(),
                        'excerpt' => $describe($vital),
                    ];
                }
            }
        }

        return $hits;
    }
}
