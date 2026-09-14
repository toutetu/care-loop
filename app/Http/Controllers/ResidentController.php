<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreResidentRequest;
use App\Models\CareLevel;
use App\Models\CarePlanGoal;
use App\Models\GoalProgressItem;
use App\Models\GoalProgressReport;
use App\Models\Resident;
use App\Models\RiskAssessment;
use App\Models\RiskFinding;
use App\Models\ServiceRecord;
use App\Models\User;
use App\Models\VerbalContactTask;
use App\Models\WeightRecord;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ご利用者の一覧と詳細。
 *
 * 詳細画面は、AI機能の出力を職員が確認する場所でもある。
 * リスク兆候も目標進捗も、根拠となった記録へ辿れる形で並べている。
 * 根拠を確かめられない出力は、記録に転記してはいけないという方針のため
 * （要件定義 7.3節 Human-in-the-Loop）。
 */
class ResidentController extends Controller
{
    /** 詳細画面に並べる記録の件数。直近の利用状況が分かれば足りる。 */
    private const RECENT_RECORDS = 12;

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Resident::class);

        /** @var User $user */
        $user = $request->user();

        $residents = Resident::query()
            ->where('facility_id', $user->facility_id)
            ->active()
            ->with('careLevel')
            ->withCount([
                // 未確認の指摘がいくつ残っているかを一覧に出す。
                // 詳細を開かないと分からない状態では、見落としが起きる。
                'riskAssessments as unreviewed_risk_count' => fn ($query) => $query
                    ->whereNull('reviewed_at')
                    ->whereHas('findings', fn ($inner) => $inner->highSeverity()),
            ])
            ->get()
            // 氏名カナは暗号化しているためSQLでは並べ替えられない（要件定義 9.3節）
            ->sortBy(fn (Resident $resident) => $resident->name_kana)
            ->values();

        return Inertia::render('residents/index', [
            // 介護職員には登録ボタンを出さない。押して403になるまで
            // 分からない状態を作らない。
            'canCreate' => $user->can('create', Resident::class),
            'residents' => $residents->map(fn (Resident $resident): array => [
                'id' => $resident->id,
                'name' => $resident->name,
                'nameKana' => $resident->name_kana,
                'age' => $resident->age,
                'gender' => $resident->gender === 'male' ? '男性' : '女性',
                'careLevel' => $resident->careLevel?->name,
                'weekdays' => $this->weekdayLabels($resident),
                'nextVisit' => $resident->nextServiceDate()?->translatedFormat('n月j日（D）'),
                // withCount が付けた集計列。モデルの属性ではなく、
                // この問い合わせでしか存在しないので getAttribute で取る。
                'unreviewedRiskCount' => (int) $resident->getAttribute('unreviewed_risk_count'),
            ])->all(),
        ]);
    }

    /**
     * ご利用者の登録画面。
     */
    public function create(): Response
    {
        Gate::authorize('create', Resident::class);

        return Inertia::render('residents/form', [
            'resident' => null,
            'careLevels' => $this->careLevels(),
        ]);
    }

    /**
     * ご利用者を登録する。
     *
     * 【同姓同名を拒まない】
     * 同じ事業所に同姓同名の方がいることは実際にある。名前で弾くと、
     * 本当に必要な登録ができなくなる。重複の可能性は画面で知らせ、
     * 登録するかどうかは職員が決める。
     */
    public function store(StoreResidentRequest $request): RedirectResponse
    {
        Gate::authorize('create', Resident::class);

        /** @var User $user */
        $user = $request->user();

        $resident = new Resident;
        $resident->facility_id = $user->facility_id;
        // name_kana_hash は booted() が保存時に計算する（Resident モデル）
        $resident->fill($request->validated())->save();

        return to_route('residents.show', $resident)
            ->with('success', "{$resident->name} 様を登録しました。");
    }

    /**
     * ご利用者情報の編集画面。
     */
    public function edit(Resident $resident): Response
    {
        Gate::authorize('update', $resident);

        return Inertia::render('residents/form', [
            'resident' => [
                'id' => $resident->id,
                'name' => $resident->name,
                'name_kana' => $resident->name_kana,
                'care_level_id' => $resident->care_level_id,
                'birth_date' => $resident->birth_date?->toDateString(),
                'gender' => $resident->gender,
                'insurance_number' => $resident->insurance_number,
                'address' => $resident->address,
                'phone' => $resident->phone,
                'family_contact' => $resident->family_contact,
                'medical_history' => $resident->medical_history,
                'care_manager_name' => $resident->care_manager_name,
                'started_at' => $resident->started_at?->toDateString(),
                'ended_at' => $resident->ended_at?->toDateString(),
                'service_weekdays' => $resident->service_weekdays ?? [],
            ],
            'careLevels' => $this->careLevels(),
        ]);
    }

    public function update(StoreResidentRequest $request, Resident $resident): RedirectResponse
    {
        Gate::authorize('update', $resident);

        $resident->fill($request->validated())->save();

        return to_route('residents.show', $resident)
            ->with('success', "{$resident->name} 様の情報を更新しました。");
    }

    /**
     * 要介護度の選択肢。軽度から重度の順に並べる。
     *
     * @return list<array<string, mixed>>
     */
    private function careLevels(): array
    {
        return array_values(CareLevel::query()
            ->orderBy('sort_order')
            ->get()
            ->map(fn (CareLevel $level): array => [
                'id' => $level->id,
                'name' => $level->name,
            ])->all());
    }

    public function show(Request $request, Resident $resident): Response
    {
        Gate::authorize('view', $resident);

        /** @var User $user */
        $user = $request->user();

        $resident->load(['careLevel', 'facility']);

        return Inertia::render('residents/show', [
            'canEdit' => $user->can('update', $resident),
            'resident' => [
                'id' => $resident->id,
                'name' => $resident->name,
                'nameKana' => $resident->name_kana,
                'age' => $resident->age,
                'gender' => $resident->gender === 'male' ? '男性' : '女性',
                'careLevel' => $resident->careLevel?->name,
                'medicalHistory' => $resident->medical_history,
                'familyContact' => $resident->family_contact,
                'careManager' => $resident->care_manager_name,
                'weekdays' => $this->weekdayLabels($resident),
                'nextVisit' => $resident->nextServiceDate()?->translatedFormat('n月j日（D）'),
                'startedAt' => $resident->started_at?->translatedFormat('Y年n月j日'),
            ],
            'weights' => $this->weights($resident),
            'water' => $this->water($resident),
            'carePlan' => $this->carePlan($resident),
            'records' => $this->recentRecords($resident, $user),
            'riskAssessment' => $this->latestRiskAssessment($resident),
            'goalProgress' => $this->latestGoalProgress($resident),
            'verbalContacts' => $this->verbalContacts($resident),
        ]);
    }

    // ---------------------------------------------------------------

    /** @return list<string> */
    private function weekdayLabels(Resident $resident): array
    {
        $names = [1 => '月', 2 => '火', 3 => '水', 4 => '木', 5 => '金', 6 => '土', 7 => '日'];

        return array_values(array_map(
            fn (int $iso): string => $names[$iso] ?? '?',
            $resident->service_weekdays ?? [],
        ));
    }

    /**
     * 体重の推移。減少率はグラフを読まなくても分かるよう、数値でも出す。
     *
     * @return list<array<string, mixed>>
     */
    private function weights(Resident $resident): array
    {
        $records = $resident->weightRecords()
            ->orderBy('measured_on')
            ->get();

        // array_values で添字を振り直す。連番でない配列はJSONにするとオブジェクトに
        // なり、画面側で配列として扱えなくなる。
        return array_values($records->map(fn (WeightRecord $record): array => [
            'date' => $record->measured_on->translatedFormat('n月'),
            'weightKg' => (float) $record->weight_kg,
            'lossRate' => $record->lossRateFromPrevious(),
        ])->all());
    }

    /**
     * 通所日ごとの水分摂取量。目標に届いているかを見る。
     *
     * @return array<string, mixed>
     */
    private function water(Resident $resident): array
    {
        $records = $resident->serviceRecords()
            ->attended()
            ->whereNotNull('total_water_ml')
            ->latest('service_date')
            ->limit(10)
            ->get()
            ->reverse()
            ->values();

        return [
            'target' => (int) config('careloop.risk_thresholds.daily_water_ml'),
            'series' => $records->map(fn (ServiceRecord $record): array => [
                'date' => $record->service_date->translatedFormat('n/j'),
                'ml' => (int) $record->total_water_ml,
            ])->all(),
        ];
    }

    /**
     * 有効な通所介護計画書と短期目標。
     *
     * @return array<string, mixed>|null
     */
    private function carePlan(Resident $resident): ?array
    {
        $plan = $resident->activeCarePlan();

        if ($plan === null) {
            return null;
        }

        return [
            'id' => $plan->id,
            'periodFrom' => $plan->period_from->translatedFormat('Y年n月j日'),
            'periodTo' => $plan->period_to->translatedFormat('Y年n月j日'),
            'longTermGoal' => $plan->long_term_goal,
            'goals' => $plan->goals()->orderBy('sort_order')->get()
                ->map(fn (CarePlanGoal $goal): array => [
                    'id' => $goal->id,
                    'text' => $goal->goal_text,
                ])->all(),
        ];
    }

    /**
     * 直近のサービス提供記録。連絡帳と記録入力への入口になる。
     *
     * @return list<array<string, mixed>>
     */
    private function recentRecords(Resident $resident, User $user): array
    {
        $records = $resident->serviceRecords()
            ->with(['vitalSigns', 'mealRecords', 'recorder'])
            ->latest('service_date')
            ->limit(self::RECENT_RECORDS)
            ->get();

        return array_values($records->map(function (ServiceRecord $record) use ($user): array {
            $vital = $record->vitalSigns->first();
            $lunch = $record->mealRecords->firstWhere('meal_type', 'lunch');

            return [
                'id' => $record->id,
                'date' => $record->service_date->translatedFormat('n月j日（D）'),
                'temperature' => $vital?->temperature,
                'waterMl' => $record->total_water_ml,
                'stapleRate' => $lunch?->staple_rate,
                'bathing' => $record->bathing_performed,
                'recorder' => $record->recorder?->name,
                'confirmed' => $record->isConfirmed(),
                'hasAiDraft' => $record->hasUnconfirmedAiDraft(),
                'excerpt' => mb_strimwidth((string) $record->record_text, 0, 60, '…'),
                // 一般職員は自分が記録したものだけ編集できる（ServiceRecordPolicy）
                'canEdit' => $user->can('update', $record),
            ];
        })->all());
    }

    /**
     * 最新のリスク兆候抽出。
     *
     * 指摘は検出元で並べ替えず、重要度の順に出す。ルールベースとLLMを
     * 分けて並べると、職員が「AIの欄」を読み飛ばすようになるため。
     * 区別はバッジで示し、並びは現場の優先度に合わせる。
     *
     * @return array<string, mixed>|null
     */
    private function latestRiskAssessment(Resident $resident): ?array
    {
        /** @var RiskAssessment|null $assessment */
        $assessment = $resident->riskAssessments()
            ->with('findings')
            ->latest('assessed_at')
            ->first();

        if ($assessment === null) {
            return null;
        }

        $findings = $assessment->findings
            ->sortByDesc(fn (RiskFinding $finding) => $finding->severity->weight())
            ->values();

        return [
            'id' => $assessment->id,
            'assessedAt' => $assessment->assessed_at->translatedFormat('Y年n月j日'),
            'periodFrom' => $assessment->period_from->translatedFormat('n月j日'),
            'periodTo' => $assessment->period_to->translatedFormat('n月j日'),
            'noRiskDetected' => $assessment->no_risk_detected,
            'confidence' => $assessment->confidence,
            'isReviewed' => $assessment->isReviewed(),
            'findings' => $findings->map(fn (RiskFinding $finding): array => [
                'id' => $finding->id,
                'category' => $finding->category->label(),
                'severity' => $finding->severity->value,
                'severityLabel' => $finding->severity->label(),
                'source' => $finding->source->value,
                'sourceLabel' => $finding->source->label(),
                'isDeterministic' => $finding->source->isDeterministic(),
                'title' => $finding->title,
                'reason' => $finding->reason,
                'evidence' => $this->evidence($finding->evidence),
                'suggestedActions' => is_array($finding->suggested_actions)
                    ? array_values($finding->suggested_actions)
                    : [],
            ])->all(),
        ];
    }

    /**
     * 最新の目標進捗要約。
     *
     * @return array<string, mixed>|null
     */
    private function latestGoalProgress(Resident $resident): ?array
    {
        /** @var GoalProgressReport|null $report */
        $report = $resident->goalProgressReports()
            ->with(['items.goal'])
            ->latest('period_to')
            ->first();

        if ($report === null) {
            return null;
        }

        return [
            'id' => $report->id,
            'periodFrom' => $report->period_from->translatedFormat('n月j日'),
            'periodTo' => $report->period_to->translatedFormat('n月j日'),
            'overallSummary' => $report->overall_summary,
            'nextActions' => is_array($report->next_actions) ? array_values($report->next_actions) : [],
            'confidence' => $report->confidence,
            'isLowConfidence' => $report->isLowConfidence(),
            'items' => $report->items->map(fn (GoalProgressItem $item): array => [
                'id' => $item->id,
                'goalText' => $item->goal?->goal_text,
                'status' => $item->progress_status->value,
                'statusLabel' => $item->progress_status->label(),
                'needsAttention' => $item->progress_status->needsAttention(),
                'comment' => $item->comment,
                'evidence' => $this->evidence($item->evidence),
            ])->all(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function verbalContacts(Resident $resident): array
    {
        $tasks = $resident->verbalContactTasks()
            ->pending()
            ->get();

        return array_values($tasks->map(fn (VerbalContactTask $task): array => [
            'id' => $task->id,
            'topic' => $task->topic,
            'reason' => $task->reason,
            'urgencyLabel' => $task->urgency === 'same_day' ? '当日中' : '次回利用時',
            'recordId' => $task->service_record_id,
        ])->all());
    }

    /**
     * 根拠として添えられた記録を、画面で開ける形に整える。
     *
     * 根拠のない指摘を出さないための項目なので、記録IDが欠けている場合も
     * 隠さずそのまま渡す。画面側でリンクにならないことで、職員が
     * 「原典を確認できない指摘である」と気づける。
     *
     * @param  mixed  $evidence
     * @return list<array<string, mixed>>
     */
    private function evidence($evidence): array
    {
        if (! is_array($evidence)) {
            return [];
        }

        $items = [];

        foreach ($evidence as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $items[] = [
                'recordId' => isset($entry['record_id']) ? (int) $entry['record_id'] : null,
                // 保存は ISO 形式のまま。機械が読む値と人が読む値を分ける。
                // 画面に出すときだけ和暦の書式に整える。
                'date' => isset($entry['date']) ? $this->displayDate((string) $entry['date']) : null,
                'excerpt' => isset($entry['excerpt']) ? (string) $entry['excerpt'] : '',
            ];
        }

        return $items;
    }

    /**
     * 根拠の日付を画面用の書式にする。
     *
     * 値はLLMの出力やルールベースの算出結果として入ってくるため、
     * 日付として読めないものが混ざりうる。その場合は加工せずそのまま出す。
     * 表示のために情報を捨てない。
     */
    private function displayDate(string $value): string
    {
        try {
            return Date::parse($value)->translatedFormat('n月j日（D）');
        } catch (InvalidFormatException) {
            return $value;
        }
    }
}
