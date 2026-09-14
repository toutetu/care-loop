<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\ServiceRecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * サービス提供記録。介護保険法上の法定文書。
 *
 * 【4種類のテキスト】
 *   raw_note      … 音声入力された原文。絶対に書き換えない
 *   record_text   … 記録用（法定文書の本体）
 *   family_text   … ご家族向け
 *   handover_note … 申し送り用
 *
 * raw_note を残すことで、AIが何を変えたのかを後から検証できる。
 * 検証できない記録は法定文書として使えない。
 *
 * @property int $id
 * @property int $resident_id
 * @property int|null $recorded_by
 * @property CarbonInterface $service_date
 * @property string $attendance_status
 * @property int|null $total_water_ml
 * @property string|null $raw_note
 * @property string|null $record_text
 * @property string|null $family_text
 * @property string|null $handover_note
 * @property bool $record_text_edited_by_human
 * @property bool $family_text_edited_by_human
 * @property int|null $llm_job_id
 * @property CarbonInterface|null $confirmed_at
 */
#[Fillable([
    'resident_id', 'recorded_by', 'service_date', 'arrival_time', 'departure_time',
    'attendance_status', 'absence_reason', 'total_water_ml', 'bathing_performed',
    'raw_note', 'record_text', 'family_text', 'handover_note',
    'record_text_edited_by_human', 'family_text_edited_by_human',
    'llm_job_id', 'confirmed_at',
])]
class ServiceRecord extends Model
{
    /** @use HasFactory<ServiceRecordFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'service_date' => 'date',
            'confirmed_at' => 'datetime',
            'record_text_edited_by_human' => 'boolean',
            'family_text_edited_by_human' => 'boolean',
            'bathing_performed' => 'boolean',
        ];
    }

    // ---------------------------------------------------------------
    // スコープ
    // ---------------------------------------------------------------

    /**
     * LLMへ渡す記録の抽出に使う。
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeInPeriod(Builder $query, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return $query->whereBetween('service_date', [$from, $to]);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeAttended(Builder $query): Builder
    {
        return $query->where('attendance_status', 'attended');
    }

    /**
     * 自由記述が入っている記録のみ。LLMの入力ソースになる。
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithNote(Builder $query): Builder
    {
        return $query->whereNotNull('raw_note')->orWhereNotNull('record_text');
    }

    // ---------------------------------------------------------------
    // 状態
    // ---------------------------------------------------------------

    /** AIの整形結果が未確認のまま残っているか。画面で警告を出すために使う。 */
    public function hasUnconfirmedAiDraft(): bool
    {
        return $this->llm_job_id !== null && $this->confirmed_at === null;
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    // ---------------------------------------------------------------
    // リレーション
    // ---------------------------------------------------------------

    /**
     * @return BelongsTo<Resident, $this>
     */
    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * @return BelongsTo<LlmJob, $this>
     */
    public function llmJob(): BelongsTo
    {
        return $this->belongsTo(LlmJob::class);
    }

    /**
     * @return HasMany<VitalSign, $this>
     */
    public function vitalSigns(): HasMany
    {
        return $this->hasMany(VitalSign::class)->orderBy('measured_at');
    }

    /**
     * @return HasMany<MealRecord, $this>
     */
    public function mealRecords(): HasMany
    {
        return $this->hasMany(MealRecord::class);
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
}
