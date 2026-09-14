<?php

namespace App\Http\Controllers;

use App\Enums\BathingType;
use App\Models\Resident;
use App\Models\ServiceRecord;
use App\Models\User;
use Carbon\CarbonInterface;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 一括入力（入浴・食事・バイタル）。
 *
 * 【記録入力画面と役割が違う】
 * 記録入力画面は「このご利用者の1日」を1画面で完結させる場所である。
 * こちらは「この作業を、担当した全員ぶん続けて入れる」場所になる。
 * 入浴介助を終えた職員は、浴室から出たところで担当した方を順に入れたい。
 * ご利用者を1人ずつ開き直すのは、その場面に合っていない。
 *
 * 同じ事実をどちらからでも入れられる。入れた先はどちらも同じ表なので、
 * 記録入力画面を開けば一括入力で入れた分も並ぶ。
 *
 * 【その日に利用のある方だけを出す】
 * 欠席の方や利用日でない方まで並べると、入力欄の数だけが増えて探しにくい。
 */
class BatchEntryController extends Controller
{
    /** 扱う種別。ルートのパラメータと画面の切り替えに使う。 */
    private const KINDS = ['bathing', 'meal', 'vital'];

    public function index(Request $request, string $kind): Response
    {
        abort_unless(in_array($kind, self::KINDS, true), 404);
        Gate::authorize('viewAny', Resident::class);

        /** @var User $user */
        $user = $request->user();

        $date = $this->resolveDate($request, $user->facility_id);

        $records = ServiceRecord::query()
            ->whereHas('resident', fn ($query) => $query->where('facility_id', $user->facility_id))
            ->whereDate('service_date', $date)
            ->where('attendance_status', 'attended')
            ->with([
                'resident.careLevel',
                'bathingRecords.recorder',
                'mealRecords.recorder',
                'vitalSigns.recorder',
            ])
            ->get()
            // 氏名カナは暗号化しているためSQLでは並べ替えられない（要件定義 9.3節）
            ->sortBy(fn (ServiceRecord $record) => $record->resident->name_kana)
            ->values();

        return Inertia::render('records/batch', [
            'kind' => $kind,
            'day' => [
                'date' => $date->toDateString(),
                'label' => $date->translatedFormat('n月j日（D）'),
                'isToday' => $date->isToday(),
            ],
            'rows' => $records->map(fn (ServiceRecord $record): array => [
                'recordId' => $record->id,
                'residentId' => $record->resident_id,
                'name' => $record->resident->name,
                'careLevel' => $record->resident->careLevel?->name,
                'canEdit' => $user->can('update', $record),
                'entries' => $this->entriesOf($record, $kind),
            ])->values()->all(),
            'mealForms' => ['常食', '一口大', '刻み', 'ミキサー'],
            'bathingTypes' => array_map(fn (BathingType $type): array => [
                'value' => $type->value,
                'label' => $type->label(),
            ], BathingType::cases()),
        ]);
    }

    /**
     * まとめて保存する。
     *
     * 【入力のあった行だけ足す】
     * 20名ぶんの欄を出して、そのうち5名だけ入れることのほうが多い。
     * 空欄の行まで記録を作ると、測っていないものが記録として残る。
     *
     * 【途中で落ちたら全部やり直す】
     * 何人ぶんが入ったのか分からない状態が、いちばん困る。
     */
    public function store(Request $request, string $kind): RedirectResponse
    {
        abort_unless(in_array($kind, self::KINDS, true), 404);

        /** @var array<int, array<string, mixed>> $entries */
        $entries = $request->array('entries');
        $userId = $request->user()?->id;
        $saved = 0;

        DB::transaction(function () use ($entries, $kind, $userId, &$saved): void {
            foreach ($entries as $recordId => $values) {
                $record = ServiceRecord::query()->find($recordId);

                if ($record === null) {
                    continue;
                }

                // 1行ずつ権限を見る。一覧に出ているからといって、
                // 書ける記録だとは限らない（別の事業所の記録が混ざる経路を塞ぐ）。
                Gate::authorize('update', $record);

                $saved += match ($kind) {
                    'bathing' => $this->storeBathing($record, $values, $userId),
                    'meal' => $this->storeMeal($record, $values, $userId),
                    default => $this->storeVital($record, $values, $userId),
                };
            }
        });

        return back()->with(
            'success',
            $saved === 0
                ? '入力された項目がありませんでした。'
                : "{$saved}名ぶんの記録を追加しました。",
        );
    }

    // ---------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $values
     */
    private function storeBathing(ServiceRecord $record, array $values, ?int $userId): int
    {
        $type = $values['bathing_type'] ?? '';

        if (! is_string($type) || BathingType::tryFrom($type) === null) {
            return 0;
        }

        $record->bathingRecords()->create([
            'recorded_by' => $userId,
            'bathed_at' => now(),
            'bathing_type' => $type,
        ]);

        return 1;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function storeMeal(ServiceRecord $record, array $values, ?int $userId): int
    {
        $staple = $this->rate($values['staple_rate'] ?? null);
        $side = $this->rate($values['side_rate'] ?? null);
        $choking = (bool) ($values['choking'] ?? false);

        if ($staple === null && $side === null && ! $choking) {
            return 0;
        }

        $mealType = $values['meal_type'] ?? 'lunch';

        $record->mealRecords()->create([
            'recorded_by' => $userId,
            'recorded_at' => now(),
            'meal_type' => in_array($mealType, ['lunch', 'snack'], true) ? $mealType : 'lunch',
            'staple_rate' => $staple,
            'side_rate' => $side,
            'meal_form' => is_string($values['meal_form'] ?? null) && $values['meal_form'] !== ''
                ? $values['meal_form']
                : null,
            'choking' => $choking,
        ]);

        return 1;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function storeVital(ServiceRecord $record, array $values, ?int $userId): int
    {
        $columns = ['temperature', 'systolic_bp', 'diastolic_bp', 'pulse', 'spo2'];
        $filled = [];

        foreach ($columns as $column) {
            $value = $values[$column] ?? null;

            // 空欄は未測定として残す。0 で埋めると「測って0だった」と読める。
            $filled[$column] = ($value === null || $value === '') ? null : $value;
        }

        if (array_filter($filled, fn ($value): bool => $value !== null) === []) {
            return 0;
        }

        $record->vitalSigns()->create([
            ...$filled,
            'recorded_by' => $userId,
            'measured_at' => now(),
            'timing' => is_string($values['timing'] ?? null) && $values['timing'] !== ''
                ? $values['timing']
                : null,
        ]);

        return 1;
    }

    /** 0〜100 に収まる整数だけ通す。範囲外は未入力として扱う。 */
    private function rate(mixed $value): ?int
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $rate = (int) $value;

        return ($rate >= 0 && $rate <= 100) ? $rate : null;
    }

    /**
     * すでに入っている分。誰がいつ入れたかを添えて返す。
     *
     * @return list<array<string, mixed>>
     */
    private function entriesOf(ServiceRecord $record, string $kind): array
    {
        $entries = match ($kind) {
            'bathing' => $record->bathingRecords->map(fn ($bathing): array => [
                'id' => $bathing->id,
                'when' => $bathing->bathed_at?->translatedFormat('H:i'),
                'text' => $bathing->bathing_type->label(),
                'recorder' => $bathing->recorder?->name,
            ]),
            'meal' => $record->mealRecords->map(fn ($meal): array => [
                'id' => $meal->id,
                'when' => $meal->recorded_at?->translatedFormat('H:i'),
                'text' => implode(' ／ ', array_filter([
                    $meal->meal_type === 'snack' ? 'おやつ' : '昼食',
                    $meal->staple_rate !== null ? "主食 {$meal->staple_rate}%" : null,
                    $meal->side_rate !== null ? "副菜 {$meal->side_rate}%" : null,
                    $meal->choking ? 'むせ込みあり' : null,
                ])),
                'recorder' => $meal->recorder?->name,
            ]),
            default => $record->vitalSigns->map(fn ($vital): array => [
                'id' => $vital->id,
                'when' => $vital->measured_at->translatedFormat('H:i'),
                'text' => implode(' ／ ', array_filter([
                    $vital->temperature !== null ? "体温 {$vital->temperature} ℃" : null,
                    $vital->systolic_bp !== null ? "血圧 {$vital->systolic_bp}/{$vital->diastolic_bp}" : null,
                    $vital->pulse !== null ? "脈拍 {$vital->pulse}" : null,
                    $vital->spo2 !== null ? "SpO2 {$vital->spo2}%" : null,
                ])) ?: '記録なし',
                'recorder' => $vital->recorder?->name,
            ]),
        };

        return array_values($entries->all());
    }

    /**
     * 表示する日付を決める。記録一覧と同じ考え方で、休業日に空を見せない。
     */
    private function resolveDate(Request $request, ?int $facilityId): CarbonInterface
    {
        $requested = $request->string('date')->value();

        if ($requested !== '') {
            try {
                return Date::parse($requested)->startOfDay();
            } catch (InvalidFormatException) {
                // 日付として読めない指定は無視して既定の動きに戻す
            }
        }

        $inFacility = fn ($query) => $query->where('facility_id', $facilityId);

        $hasToday = ServiceRecord::query()
            ->whereHas('resident', $inFacility)
            ->whereDate('service_date', today())
            ->exists();

        if ($hasToday) {
            return today();
        }

        $latest = ServiceRecord::query()
            ->whereHas('resident', $inFacility)
            ->max('service_date');

        return is_string($latest) ? Date::parse($latest)->startOfDay() : today();
    }
}
