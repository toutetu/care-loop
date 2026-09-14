<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\BlindIndex;
use Carbon\CarbonInterface;
use Database\Factories\ResidentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 利用者。要配慮個人情報（健康状態・病歴）を保持する唯一のテーブル。
 *
 * 【暗号化】
 * 個人を特定する属性は encrypted キャストで暗号化する。DBダンプが流出しても
 * APP_KEY がなければ復号できない。ただしこれは多層防御の最終層であり、
 * サーバー自体が侵害されれば復号される（要件定義 9.3.3節）。
 *
 * 【暗号化の代償】
 * 暗号化した属性は SQL で検索・並べ替えができない。
 *   - 氏名カナの検索 … name_kana_hash による Blind Index で代替（scopeWhereKana）
 *   - 氏名順の並べ替え … PHP 側で行う（本システムの想定規模は1事業所20名程度）
 * この制約を承知のうえで暗号化を選んでいる。
 *
 * @property int $id
 * @property int $facility_id
 * @property int|null $care_level_id
 * @property string $name 暗号化
 * @property string $name_kana 暗号化
 * @property string $name_kana_hash Blind Index（自動生成）
 * @property string|null $insurance_number 暗号化
 * @property string|null $address 暗号化
 * @property string|null $phone 暗号化
 * @property string|null $family_contact 暗号化
 * @property string|null $medical_history 暗号化
 * @property CarbonInterface|null $birth_date
 * @property string|null $gender
 * @property array<int, int>|null $service_weekdays
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $ended_at
 * @property string|null $care_manager_name
 * @property-read int|null $age
 */
#[Fillable([
    'facility_id', 'care_level_id', 'name', 'name_kana', 'insurance_number',
    'address', 'phone', 'family_contact', 'medical_history', 'birth_date',
    'gender', 'service_weekdays', 'started_at', 'ended_at', 'care_manager_name',
])]
class Resident extends Model
{
    /** @use HasFactory<ResidentFactory> */
    use Auditable, HasFactory, SoftDeletes;

    /**
     * 編集履歴に出す項目名。
     *
     * 列名のまま出しても、誰が読んでも分かるわけではない。
     * 「何が変わったのか」を確かめる画面なので、画面と同じ言葉で出す。
     *
     * @return array<string, string>
     */
    public function auditLabels(): array
    {
        return [
            'name' => 'お名前',
            'name_kana' => 'お名前（カナ）',
            'care_level_id' => '要介護度',
            'birth_date' => '生年月日',
            'gender' => '性別',
            'insurance_number' => '被保険者番号',
            'address' => 'ご住所',
            'phone' => '電話番号',
            'family_contact' => 'ご家族の連絡先',
            'medical_history' => '既往歴',
            'care_manager_name' => '担当の介護支援専門員',
            'service_weekdays' => '利用曜日',
            'started_at' => '利用開始日',
            'ended_at' => '利用終了日',
            'facility_id' => '事業所',
        ];
    }

    protected function casts(): array
    {
        return [
            'name' => 'encrypted',
            'name_kana' => 'encrypted',
            'insurance_number' => 'encrypted',
            'address' => 'encrypted',
            'phone' => 'encrypted',
            'family_contact' => 'encrypted',
            'medical_history' => 'encrypted',
            'birth_date' => 'date',
            'started_at' => 'date',
            'ended_at' => 'date',
            'service_weekdays' => 'array',
        ];
    }

    /**
     * 氏名カナの Blind Index は保存のたびに再計算する。
     *
     * HMAC は決定的（同じ入力なら必ず同じ出力）なので、毎回計算しても
     * 結果は変わらない。isDirty による分岐を入れるより、常に整合が取れている
     * 状態を保証するほうが安全。
     */
    protected static function booted(): void
    {
        static::saving(function (self $resident): void {
            $resident->name_kana_hash = BlindIndex::hash($resident->name_kana) ?? '';
        });
    }

    // ---------------------------------------------------------------
    // スコープ
    // ---------------------------------------------------------------

    /**
     * 氏名カナで検索する。暗号化した列は検索できないため Blind Index を使う。
     * 表記ゆれ（半角カナ・ひらがな・空白）は BlindIndex 側で吸収する。
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWhereKana(Builder $query, string $kana): Builder
    {
        return $query->where('name_kana_hash', BlindIndex::hash($kana));
    }

    /**
     * 利用中（終了日が未設定、または未到来）の利用者。
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where(function (Builder $q): void {
            $q->whereNull('ended_at')->orWhere('ended_at', '>=', today());
        });
    }

    // ---------------------------------------------------------------
    // 属性
    // ---------------------------------------------------------------

    /**
     * 年齢。LLMへ送るときは生年月日ではなくこの値を使う（要件定義 7.2節）。
     *
     * @return Attribute<int|null, never>
     */
    protected function age(): Attribute
    {
        return Attribute::make(
            get: fn (): ?int => $this->birth_date?->age,
        );
    }

    // ---------------------------------------------------------------
    // リレーション
    // ---------------------------------------------------------------

    /**
     * @return BelongsTo<Facility, $this>
     */
    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    /**
     * @return BelongsTo<CareLevel, $this>
     */
    public function careLevel(): BelongsTo
    {
        return $this->belongsTo(CareLevel::class);
    }

    /**
     * @return HasMany<CarePlan, $this>
     */
    public function carePlans(): HasMany
    {
        return $this->hasMany(CarePlan::class);
    }

    /**
     * @return HasMany<ServiceRecord, $this>
     */
    public function serviceRecords(): HasMany
    {
        return $this->hasMany(ServiceRecord::class);
    }

    /**
     * @return HasMany<WeightRecord, $this>
     */
    public function weightRecords(): HasMany
    {
        return $this->hasMany(WeightRecord::class);
    }

    /**
     * @return HasMany<IncidentReport, $this>
     */
    public function incidentReports(): HasMany
    {
        return $this->hasMany(IncidentReport::class);
    }

    /**
     * @return HasMany<VerbalContactTask, $this>
     */
    public function verbalContactTasks(): HasMany
    {
        return $this->hasMany(VerbalContactTask::class);
    }

    /**
     * @return HasMany<RiskAssessment, $this>
     */
    public function riskAssessments(): HasMany
    {
        return $this->hasMany(RiskAssessment::class);
    }

    /**
     * @return HasMany<GoalProgressReport, $this>
     */
    public function goalProgressReports(): HasMany
    {
        return $this->hasMany(GoalProgressReport::class);
    }

    /**
     * 次回のご利用予定日。
     *
     * 別途「次回予定」を管理する項目は設けない。利用曜日（service_weekdays）
     * から算出できるものを二重に持つと、必ずどちらかが古くなる。
     * 予定を登録・更新する手間を現場に負わせないための判断でもある。
     *
     * 祝日や臨時休業までは見ない。連絡帳に載せる目安であり、
     * 変更があれば職員が口頭でお伝えするため。
     */
    public function nextServiceDate(?CarbonInterface $after = null): ?CarbonInterface
    {
        $weekdays = $this->service_weekdays ?? [];

        if ($weekdays === []) {
            return null;
        }

        $date = ($after ?? today())->addDay();

        // 週の巡回1周ぶんだけ探す。見つからなければ利用曜日の設定が不正
        for ($i = 0; $i < 7; $i++) {
            if (in_array($date->dayOfWeekIso, $weekdays, true)) {
                return $date;
            }

            $date = $date->addDay();
        }

        return null;
    }

    /** 現在有効な通所介護計画書。 */
    public function activeCarePlan(): ?CarePlan
    {
        return $this->carePlans()
            ->where('status', 'active')
            ->where('period_from', '<=', today())
            ->where('period_to', '>=', today())
            ->latest('period_from')
            ->first();
    }
}
