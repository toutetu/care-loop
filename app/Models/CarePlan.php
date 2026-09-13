<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\CarePlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 通所介護計画書。
 *
 * @property int $id
 * @property int $resident_id
 * @property CarbonInterface $period_from
 * @property CarbonInterface $period_to
 * @property string $long_term_goal
 * @property string $status
 * @property int|null $created_by
 */
#[Fillable([
    'resident_id', 'period_from', 'period_to', 'long_term_goal', 'status', 'created_by',
])]
class CarePlan extends Model
{
    /** @use HasFactory<CarePlanFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'period_from' => 'date',
            'period_to' => 'date',
        ];
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /**
     * 指定日を含む期間の計画書。
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeCovering(Builder $query, CarbonInterface $date): Builder
    {
        return $query->where('period_from', '<=', $date)->where('period_to', '>=', $date);
    }

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
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * 短期目標。F-LLM-01 の評価単位になる。
     *
     * @return HasMany<CarePlanGoal, $this>
     */
    public function goals(): HasMany
    {
        return $this->hasMany(CarePlanGoal::class)->orderBy('sort_order');
    }

    /**
     * @return HasMany<GoalProgressReport, $this>
     */
    public function goalProgressReports(): HasMany
    {
        return $this->hasMany(GoalProgressReport::class);
    }
}
