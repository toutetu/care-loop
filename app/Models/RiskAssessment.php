<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\RiskAssessmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * リスク兆候抽出（F-LLM-02）の実行単位。個々の指摘は riskFindings に持つ。
 *
 * reviewed_by / reviewed_at があるのは、AIの出力が「気づきの提示」であって
 * 確定情報ではないという方針をデータ構造として表すため。
 * 未確認のまま放置された抽出結果を識別できる。
 *
 * @property int $id
 * @property int $resident_id
 * @property int|null $llm_job_id
 * @property CarbonInterface $period_from
 * @property CarbonInterface $period_to
 * @property CarbonInterface $assessed_at
 * @property bool $no_risk_detected
 * @property string|null $confidence
 * @property int|null $reviewed_by
 * @property CarbonInterface|null $reviewed_at
 */
#[Fillable([
    'resident_id', 'llm_job_id', 'period_from', 'period_to', 'assessed_at',
    'no_risk_detected', 'confidence', 'reviewed_by', 'reviewed_at',
])]
class RiskAssessment extends Model
{
    /** @use HasFactory<RiskAssessmentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'period_from' => 'date',
            'period_to' => 'date',
            'assessed_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'no_risk_detected' => 'boolean',
        ];
    }

    /**
     * 職員がまだ確認していない抽出結果。ダッシュボードに出す。
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeUnreviewed(Builder $query): Builder
    {
        return $query->whereNull('reviewed_at');
    }

    /**
     * ご利用者ごとに最新の抽出だけに絞る。
     *
     * 抽出は実行するたびに1件ずつ積まれ、前の結果は消さない（いつ何を
     * 指摘したかを後から辿るため）。古い結果まで数えると、同じ指摘が
     * 実行した回数だけ並ぶ。利用者詳細は最新の1件だけを見せているので、
     * 一覧や件数もそれに揃える。
     *
     * 抽出日時が同じなら、あとから作ったほうを最新とみなす。
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeLatestPerResident(Builder $query): Builder
    {
        $table = $this->getTable();

        return $query->whereNotExists(fn ($newer) => $newer
            ->selectRaw('1')
            ->from("{$table} as newer")
            ->whereColumn('newer.resident_id', "{$table}.resident_id")
            ->where(fn ($later) => $later
                ->whereColumn('newer.assessed_at', '>', "{$table}.assessed_at")
                ->orWhere(fn ($sameTime) => $sameTime
                    ->whereColumn('newer.assessed_at', "{$table}.assessed_at")
                    ->whereColumn('newer.id', '>', "{$table}.id"))));
    }

    public function markReviewed(User $user): void
    {
        $this->forceFill([
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ])->save();
    }

    public function isReviewed(): bool
    {
        return $this->reviewed_at !== null;
    }

    /**
     * @return BelongsTo<Resident, $this>
     */
    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class);
    }

    /**
     * @return BelongsTo<LlmJob, $this>
     */
    public function llmJob(): BelongsTo
    {
        return $this->belongsTo(LlmJob::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @return HasMany<RiskFinding, $this>
     */
    public function findings(): HasMany
    {
        return $this->hasMany(RiskFinding::class);
    }
}
