<?php

namespace App\Models;

use Database\Factories\GoalProgressReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * 目標進捗要約（F-LLM-01）の実行単位。モニタリング記録の下書きとして使う。
 *
 * @property int $id
 * @property int $resident_id
 * @property int|null $care_plan_id
 * @property int|null $llm_job_id
 * @property Carbon $period_from
 * @property Carbon $period_to
 * @property string|null $overall_summary
 * @property array<int, string>|null $next_actions
 * @property string|null $confidence
 * @property Carbon|null $reviewed_at
 */
#[Fillable([
    'resident_id', 'care_plan_id', 'llm_job_id', 'period_from', 'period_to',
    'overall_summary', 'next_actions', 'confidence', 'reviewed_by', 'reviewed_at',
])]
class GoalProgressReport extends Model
{
    /** @use HasFactory<GoalProgressReportFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'period_from' => 'date',
            'period_to' => 'date',
            'reviewed_at' => 'datetime',
            'next_actions' => 'array',
        ];
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeUnreviewed(Builder $query): Builder
    {
        return $query->whereNull('reviewed_at');
    }

    /**
     * 確信度が低い出力か。画面で明示的に警告を出すために使う。
     * 低確信度の要約をそのままモニタリング記録に転記させないための歯止め。
     */
    public function isLowConfidence(): bool
    {
        return $this->confidence === 'low';
    }

    public function markReviewed(User $user): void
    {
        $this->forceFill([
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ])->save();
    }

    /**
     * @return BelongsTo<Resident, $this>
     */
    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class);
    }

    /**
     * @return BelongsTo<CarePlan, $this>
     */
    public function carePlan(): BelongsTo
    {
        return $this->belongsTo(CarePlan::class);
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
     * @return HasMany<GoalProgressItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(GoalProgressItem::class);
    }
}
