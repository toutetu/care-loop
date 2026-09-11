<?php

namespace App\Models;

use Database\Factories\CarePlanGoalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * 短期目標。計画書から分離した繰り返し項目（第1正規形）。
 *
 * このテーブルが独立していることで、目標1件ごとの進捗評価（F-LLM-01）が
 * 成立する。固定列で持っていたら目標単位の評価はできない。
 *
 * @property int $id
 * @property int $care_plan_id
 * @property string $goal_text
 * @property Carbon|null $target_date
 * @property int $sort_order
 */
#[Fillable(['care_plan_id', 'goal_text', 'target_date', 'sort_order'])]
class CarePlanGoal extends Model
{
    /** @use HasFactory<CarePlanGoalFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'target_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<CarePlan, $this>
     */
    public function carePlan(): BelongsTo
    {
        return $this->belongsTo(CarePlan::class);
    }

    /**
     * この目標に対する各期間の進捗評価。
     *
     * @return HasMany<GoalProgressItem, $this>
     */
    public function progressItems(): HasMany
    {
        return $this->hasMany(GoalProgressItem::class);
    }
}
