<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\IncidentReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * ヒヤリハット・事故報告。
 *
 * family_notified を持つのは、介護保険制度上、事故・ヒヤリハットには
 * ご家族への報告義務があるため。報告したかどうかを残せない設計は
 * 制度要件を満たさない。
 *
 * @property int $id
 * @property int $resident_id
 * @property int|null $service_record_id
 * @property int|null $reported_by
 * @property string $category
 * @property string $severity
 * @property CarbonInterface $occurred_at
 * @property string $description
 * @property bool $family_notified
 */
#[Fillable([
    'resident_id', 'service_record_id', 'reported_by', 'category', 'severity',
    'occurred_at', 'description', 'response', 'prevention',
    'family_notified', 'family_notified_at',
])]
class IncidentReport extends Model
{
    /** @use HasFactory<IncidentReportFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'family_notified' => 'boolean',
            'family_notified_at' => 'datetime',
        ];
    }

    /**
     * 転倒リスクの判定に使う。設定した月数以内の記録を返す。
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeRecent(Builder $query, ?int $months = null): Builder
    {
        $months ??= (int) config('careloop.risk_thresholds.incident_months');

        return $query->where('occurred_at', '>=', now()->subMonths($months));
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOfCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    /**
     * ご家族への報告がまだ済んでいないもの。
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeAwaitingFamilyNotice(Builder $query): Builder
    {
        return $query->where('family_notified', false);
    }

    /**
     * @return BelongsTo<Resident, $this>
     */
    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class);
    }

    /**
     * @return BelongsTo<ServiceRecord, $this>
     */
    public function serviceRecord(): BelongsTo
    {
        return $this->belongsTo(ServiceRecord::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }
}
