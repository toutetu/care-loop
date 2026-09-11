<?php

namespace App\Models;

use App\Enums\ProgressStatus;
use Database\Factories\GoalProgressItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 短期目標ごとの進捗評価。care_plan_goals の1件に対応する。
 *
 * progress_status に insufficient_data を許すことで、記録が足りない期間に
 * 無理な評価をさせない。「判断できない」と言えることが、
 * 事実に基づかない出力を防ぐ最も効果的な手段になる。
 *
 * @property int $id
 * @property int $goal_progress_report_id
 * @property int|null $care_plan_goal_id
 * @property ProgressStatus $progress_status
 * @property string|null $comment
 * @property array<int, array<string, mixed>>|null $evidence
 */
#[Fillable([
    'goal_progress_report_id', 'care_plan_goal_id',
    'progress_status', 'comment', 'evidence',
])]
class GoalProgressItem extends Model
{
    /** @use HasFactory<GoalProgressItemFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'progress_status' => ProgressStatus::class,
            'evidence' => 'array',
        ];
    }

    /**
     * 職員の確認を促すべき評価（低下傾向・材料不足）。
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeNeedsAttention(Builder $query): Builder
    {
        return $query->whereIn('progress_status', [
            ProgressStatus::Declining,
            ProgressStatus::InsufficientData,
        ]);
    }

    public function hasEvidence(): bool
    {
        return is_array($this->evidence) && $this->evidence !== [];
    }

    /**
     * @return BelongsTo<GoalProgressReport, $this>
     */
    public function report(): BelongsTo
    {
        return $this->belongsTo(GoalProgressReport::class, 'goal_progress_report_id');
    }

    /**
     * @return BelongsTo<CarePlanGoal, $this>
     */
    public function goal(): BelongsTo
    {
        return $this->belongsTo(CarePlanGoal::class, 'care_plan_goal_id');
    }
}
