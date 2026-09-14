<?php

namespace App\Http\Controllers;

use App\Enums\BathingType;
use App\Http\Requests\UpdateServiceRecordRequest;
use App\Models\Resident;
use App\Models\ServiceRecord;
use App\Models\User;
use Carbon\CarbonInterface;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * サービス提供記録の入力。
 *
 * 【スマホでの入力を前提にする】
 * 記録はフロアで書く。事務所のパソコンまで戻って書くと、
 * 思い出しながら書くことになり、内容が痩せる。
 *
 * 【AIが書いた文章と、人が直した文章を区別する】
 * record_text / family_text を職員が編集したら *_edited_by_human を立てる。
 * どこまでがAIの出力で、どこからが人の判断なのかを後から追えないと、
 * 法定文書として説明できない（要件定義 7.3節）。
 */
class ServiceRecordController extends Controller
{
    /**
     * 記録の一覧。
     *
     * 【ダッシュボードから独立させた理由】
     * ダッシュボードは朝礼で全体を見る場所で、一覧は記録を埋めていく場所である。
     * 目的が違うものを1画面に混ぜると、どちらも中途半端になる。
     * 独立させたことで、日付を選ぶ・未確定だけ絞るといった操作を置ける。
     *
     * 【未確定を上に並べる】
     * この画面を開く動機は「まだ終わっていないものを片付ける」ことである。
     * 確定済みが上に並んでいると、毎回スクロールして探すことになる。
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Resident::class);

        /** @var User $user */
        $user = $request->user();

        $date = $this->resolveDate($request, $user->facility_id);
        $onlyUnconfirmed = $request->string('status')->value() === 'unconfirmed';

        $records = ServiceRecord::query()
            ->whereHas('resident', fn ($query) => $query->where('facility_id', $user->facility_id))
            ->whereDate('service_date', $date)
            ->with(['resident.careLevel', 'recorder', 'vitalSigns'])
            ->get();

        $rows = $records
            // 氏名カナは暗号化しているためSQLでは並べ替えられない（要件定義 9.3節）
            ->sortBy(fn (ServiceRecord $record) => $record->resident->name_kana)
            ->sortBy(fn (ServiceRecord $record) => $record->isConfirmed() ? 1 : 0)
            ->values();

        return Inertia::render('records/index', [
            'day' => [
                'date' => $date->toDateString(),
                'label' => $date->translatedFormat('n月j日（D）'),
                'isToday' => $date->isToday(),
            ],
            'counts' => [
                'total' => $records->count(),
                'unconfirmed' => $records->filter(fn (ServiceRecord $r) => ! $r->isConfirmed())->count(),
                'aiDraft' => $records->filter(fn (ServiceRecord $r) => $r->hasUnconfirmedAiDraft())->count(),
            ],
            'onlyUnconfirmed' => $onlyUnconfirmed,
            'records' => $this->rows(
                $onlyUnconfirmed
                    ? $rows->filter(fn (ServiceRecord $r) => ! $r->isConfirmed())->values()
                    : $rows,
                $user,
            ),
        ]);
    }

    /**
     * 表示する日付を決める。
     *
     * 指定がなければ本日。本日に記録がなければ直近の利用日へ下がる。
     * 通所介護は日曜や祝日に営業しないため、空の一覧を見せても
     * 壊れているのか休みなのか区別がつかない。
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

    /**
     * @param  Collection<int, ServiceRecord>  $records
     * @return list<array<string, mixed>>
     */
    private function rows($records, User $user): array
    {
        return array_values($records->map(function (ServiceRecord $record) use ($user): array {
            $vital = $record->vitalSigns->first();

            return [
                'recordId' => $record->id,
                'residentId' => $record->resident_id,
                'name' => $record->resident->name,
                'careLevel' => $record->resident->careLevel?->name,
                'arrivalTime' => $this->hhmm($record->arrival_time),
                'departureTime' => $this->hhmm($record->departure_time),
                'temperature' => $vital?->temperature !== null ? (float) $vital->temperature : null,
                'recorder' => $record->recorder?->name,
                'status' => match (true) {
                    $record->hasUnconfirmedAiDraft() => 'ai_draft',
                    $record->isConfirmed() => 'confirmed',
                    default => 'draft',
                },
                'bathing' => $record->bathing_type?->label(),
                'canEdit' => $user->can('update', $record),
            ];
        })->all());
    }

    /**
     * 記録を開く。
     *
     * 【閲覧と編集を分けている】
     * 開くだけなら同じ事業所の職員は誰でもできる。リスク指摘の根拠になった
     * 記録を確認できないと、AIの出力を職員が検証するという前提そのものが
     * 成り立たないためである（要件定義 7.3節）。
     *
     * 書き換えられるのは、記録した本人か管理者以上に限る（ServiceRecordPolicy）。
     * 権限がない場合は同じ画面を読み取り専用で開く。403 を返して追い返すと、
     * 根拠を確かめる手段がなくなる。
     */
    public function edit(Request $request, ServiceRecord $serviceRecord): Response
    {
        Gate::authorize('view', $serviceRecord);

        $serviceRecord->load(['resident.careLevel', 'recorder', 'vitalSigns', 'mealRecords', 'verbalContactTasks']);

        $vital = $serviceRecord->vitalSigns->first();
        $lunch = $serviceRecord->mealRecords->firstWhere('meal_type', 'lunch');

        return Inertia::render('records/edit', [
            'canEdit' => $request->user()?->can('update', $serviceRecord) ?? false,
            'record' => [
                'id' => $serviceRecord->id,
                'recorder' => $serviceRecord->recorder?->name,
                'residentId' => $serviceRecord->resident_id,
                'residentName' => $serviceRecord->resident->name,
                'careLevel' => $serviceRecord->resident->careLevel?->name,
                'date' => $serviceRecord->service_date->translatedFormat('Y年n月j日（D）'),
                'arrivalTime' => $this->hhmm($serviceRecord->arrival_time),
                'departureTime' => $this->hhmm($serviceRecord->departure_time),
                'attendanceStatus' => $serviceRecord->attendance_status,
                'absenceReason' => $serviceRecord->absence_reason,
                'bathingType' => $serviceRecord->bathing_type?->value,
                'totalWaterMl' => $serviceRecord->total_water_ml,
                'rawNote' => $serviceRecord->raw_note,
                'recordText' => $serviceRecord->record_text,
                'familyText' => $serviceRecord->family_text,
                'handoverNote' => $serviceRecord->handover_note,
                'recordTextEditedByHuman' => $serviceRecord->record_text_edited_by_human,
                'familyTextEditedByHuman' => $serviceRecord->family_text_edited_by_human,
                'confirmedAt' => $serviceRecord->confirmed_at?->translatedFormat('n月j日 H:i'),
                'hasAiDraft' => $serviceRecord->hasUnconfirmedAiDraft(),
                'vital' => [
                    'temperature' => $vital?->temperature !== null ? (float) $vital->temperature : null,
                    'systolic_bp' => $vital?->systolic_bp,
                    'diastolic_bp' => $vital?->diastolic_bp,
                    'pulse' => $vital?->pulse,
                    'spo2' => $vital?->spo2,
                ],
                'lunch' => [
                    'staple_rate' => $lunch?->staple_rate,
                    'side_rate' => $lunch?->side_rate,
                    'meal_form' => $lunch?->meal_form,
                    'choking' => (bool) $lunch?->choking,
                ],
            ],
            // 口頭連絡タスクは記録画面にも出す。生成して終わりにせず、
            // 送迎担当が必ず目にする場所へ置く（F-20）。
            'verbalContacts' => $serviceRecord->verbalContactTasks
                ->where('status.value', 'pending')
                ->values()
                ->map(fn ($task): array => [
                    'id' => $task->id,
                    'topic' => $task->topic,
                    'reason' => $task->reason,
                ])->all(),
            'mealForms' => ['常食', '一口大', '刻み', 'ミキサー'],
            // 入浴を見送った日も清拭は行う。真偽値では両者の区別が消える。
            'bathingTypes' => array_map(fn (BathingType $type): array => [
                'value' => $type->value,
                'label' => $type->label(),
            ], BathingType::cases()),
        ]);
    }

    public function update(UpdateServiceRecordRequest $request, ServiceRecord $serviceRecord): RedirectResponse
    {
        Gate::authorize('update', $serviceRecord);

        $data = $request->validated();

        DB::transaction(function () use ($request, $serviceRecord, $data): void {
            $serviceRecord->fill([
                'arrival_time' => $data['arrival_time'] ?? null,
                'departure_time' => $data['departure_time'] ?? null,
                'attendance_status' => $data['attendance_status'],
                'absence_reason' => $data['absence_reason'] ?? null,
                'bathing_type' => ($data['bathing_type'] ?? '') !== '' ? $data['bathing_type'] : null,
                'total_water_ml' => $data['total_water_ml'] ?? null,
                'raw_note' => $data['raw_note'] ?? null,
                'handover_note' => $data['handover_note'] ?? null,
            ]);

            $this->applyText($serviceRecord, 'record_text', $data['record_text'] ?? null);
            $this->applyText($serviceRecord, 'family_text', $data['family_text'] ?? null);

            // 確定はチェックを入れたときだけ。保存＝確定にすると、
            // 途中まで入力して保存した記録が確定済みになってしまう。
            //
            // boolean() を使う。チェックボックスが送ってくるのは真偽値ではなく
            // 文字列の "1" で、=== true では一致しない。バリデータの boolean
            // ルールは形式を確かめるだけで、値を変換はしない。
            if ($request->boolean('confirm') && ! $serviceRecord->isConfirmed()) {
                $serviceRecord->confirmed_at = now();
            }

            $serviceRecord->recorded_by ??= $request->user()?->id;
            $serviceRecord->save();

            $this->saveVital($serviceRecord, $data['vital'] ?? []);
            $this->saveLunch($serviceRecord, $data['lunch'] ?? []);
        });

        return back()->with('success', '記録を保存しました。');
    }

    // ---------------------------------------------------------------

    /**
     * 本文を反映し、人が手を入れたかどうかを記録する。
     *
     * AIの出力と一字一句同じなら、職員はまだ判断していない。
     * 変わっていたときだけ「人が直した」と記録する。画面上の編集操作ではなく、
     * 実際に内容が変わったかどうかで判定している。
     */
    private function applyText(ServiceRecord $record, string $column, ?string $value): void
    {
        $original = $record->getOriginal($column);

        if ($value !== $original) {
            $record->{$column} = $value;

            if ($original !== null) {
                $record->{$column.'_edited_by_human'} = true;
            }
        }
    }

    /**
     * バイタルは1件だけ扱う。
     *
     * 入浴前後で複数回測る事業所もあるため vital_signs は複数行を持てる構造だが、
     * この画面では到着時の1件を編集する。複数回の測定はこの画面の役割ではない。
     *
     * @param  array<string, mixed>  $values
     */
    private function saveVital(ServiceRecord $record, array $values): void
    {
        $values = array_filter($values, fn ($value) => $value !== null && $value !== '');

        $vital = $record->vitalSigns()->orderBy('measured_at')->first();

        if ($values === [] && $vital === null) {
            return;
        }

        if ($vital === null) {
            $vital = $record->vitalSigns()->make([
                'measured_at' => $record->service_date->copy()->setTime(9, 45),
                'timing' => 'arrival',
            ]);
        }

        // 未測定を空欄として保存できるようにする。
        // 0 で埋めると「測って0だった」と読めてしまい、記録として誤りになる。
        foreach (['temperature', 'systolic_bp', 'diastolic_bp', 'pulse', 'spo2'] as $column) {
            $vital->{$column} = $values[$column] ?? null;
        }

        $vital->service_record_id = $record->id;
        $vital->save();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function saveLunch(ServiceRecord $record, array $values): void
    {
        $hasValue = collect($values)
            ->except('choking')
            ->contains(fn ($value) => $value !== null && $value !== '');

        $lunch = $record->mealRecords()->firstWhere('meal_type', 'lunch');

        if (! $hasValue && $lunch === null) {
            return;
        }

        $lunch ??= $record->mealRecords()->make(['meal_type' => 'lunch']);

        $lunch->staple_rate = $values['staple_rate'] ?? null;
        $lunch->side_rate = $values['side_rate'] ?? null;
        $lunch->meal_form = $values['meal_form'] ?? null;
        $lunch->choking = (bool) ($values['choking'] ?? false);
        $lunch->service_record_id = $record->id;
        $lunch->save();
    }

    private function hhmm(?string $time): ?string
    {
        return $time !== null ? substr($time, 0, 5) : null;
    }
}
