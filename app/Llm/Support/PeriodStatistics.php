<?php

namespace App\Llm\Support;

use App\Models\Resident;
use App\Models\ServiceRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 対象期間の記録を集計する。
 *
 * 【なぜLLMに数えさせないのか】
 * 「1日1,200ml以上の水分摂取ができる」という目標の達成度を評価するには、
 * 何回中何回達成したかを数える必要がある。これは算術であり、
 * LLMに数えさせると結果が揺れ、検証もできなくなる。
 *
 * ここで集計した数値をプロンプトに含めて渡し、LLMには
 * 「その数値と自由記述を踏まえて、目標に対する進捗をどう評価するか」
 * という判断だけを求める（要件定義 7.1節の役割分担）。
 *
 * 出力は整形済みの行の並びにしている。唯一の利用先がプロンプトであり、
 * 中間の構造を挟んでも使う側で結局文字列に組み直すだけになるため。
 */
final class PeriodStatistics
{
    /**
     * @return list<string>
     */
    public function lines(Resident $resident, Carbon $from, Carbon $to): array
    {
        $records = $resident->serviceRecords()
            ->inPeriod($from, $to)
            ->with(['mealRecords', 'vitalSigns'])
            ->orderBy('service_date')
            ->get();

        $attended = $records->where('attendance_status', 'attended');

        $lines = [
            sprintf(
                '- 利用回数: %d回（欠席 %d回）',
                $attended->count(),
                $records->count() - $attended->count(),
            ),
        ];

        foreach ([
            $this->water($attended),
            $this->meals($attended),
            $this->weight($resident, $from, $to),
            $this->vitals($attended),
            $this->incidents($resident, $from, $to),
        ] as $line) {
            if ($line !== null) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * @param  Collection<int, ServiceRecord>  $records
     */
    private function water($records): ?string
    {
        $values = $records
            ->map(fn (ServiceRecord $r): ?int => $r->total_water_ml)
            ->filter(fn (?int $v): bool => $v !== null);

        if ($values->isEmpty()) {
            return null;
        }

        $target = (int) config('careloop.risk_thresholds.daily_water_ml');
        $achieved = $values->filter(fn (int $v): bool => $v >= $target)->count();

        return sprintf(
            '- 水分摂取: 平均 %dml（記録 %d回中、目標 %dml 達成は %d回）',
            (int) round((float) $values->avg()),
            $values->count(),
            $target,
            $achieved,
        );
    }

    /**
     * @param  Collection<int, ServiceRecord>  $records
     */
    private function meals($records): ?string
    {
        $staple = [];
        $side = [];

        foreach ($records as $record) {
            foreach ($record->mealRecords as $meal) {
                if ($meal->staple_rate !== null) {
                    $staple[] = $meal->staple_rate;
                }

                if ($meal->side_rate !== null) {
                    $side[] = $meal->side_rate;
                }
            }
        }

        if ($staple === [] && $side === []) {
            return null;
        }

        return sprintf(
            '- 食事摂取率: 主食 平均 %s / 副菜 平均 %s',
            $staple === [] ? '記録なし' : sprintf('%d%%', (int) round(array_sum($staple) / count($staple))),
            $side === [] ? '記録なし' : sprintf('%d%%', (int) round(array_sum($side) / count($side))),
        );
    }

    private function weight(Resident $resident, Carbon $from, Carbon $to): ?string
    {
        $records = $resident->weightRecords()
            ->whereBetween('measured_on', [$from, $to])
            ->orderBy('measured_on')
            ->get();

        if ($records->isEmpty()) {
            return null;
        }

        $first = $records->first();
        $last = $records->last();

        if ($records->count() === 1) {
            return sprintf('- 体重: %.1fkg（%s、1回のみの記録）', $last->weight_kg, $last->measured_on->toDateString());
        }

        return sprintf(
            '- 体重: %.1fkg → %.1fkg（%+.1fkg）',
            $first->weight_kg,
            $last->weight_kg,
            $last->weight_kg - $first->weight_kg,
        );
    }

    /**
     * @param  Collection<int, ServiceRecord>  $records
     */
    private function vitals($records): string
    {
        $thresholds = config('careloop.risk_thresholds');
        $fever = 0;
        $bp = 0;
        $spo2 = 0;

        foreach ($records as $record) {
            foreach ($record->vitalSigns as $vital) {
                if ($vital->temperature !== null && $vital->temperature >= $thresholds['temperature_high']) {
                    $fever++;
                }

                if ($vital->systolic_bp !== null
                    && ($vital->systolic_bp >= $thresholds['systolic_bp_high'] || $vital->systolic_bp <= $thresholds['systolic_bp_low'])) {
                    $bp++;
                }

                if ($vital->spo2 !== null && $vital->spo2 <= $thresholds['spo2_low']) {
                    $spo2++;
                }
            }
        }

        if ($fever === 0 && $bp === 0 && $spo2 === 0) {
            return '- バイタル: 基準を外れた記録はありません';
        }

        return sprintf('- バイタル: 発熱 %d回 / 血圧の逸脱 %d回 / SpO2低下 %d回', $fever, $bp, $spo2);
    }

    private function incidents(Resident $resident, Carbon $from, Carbon $to): string
    {
        $count = $resident->incidentReports()
            ->whereBetween('occurred_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->count();

        return sprintf('- ヒヤリハット・事故: %d件', $count);
    }
}
