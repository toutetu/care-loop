<?php

namespace App\Models;

use App\Enums\RiskCategory;
use App\Enums\RiskSeverity;
use App\Enums\RiskSource;
use Database\Factories\RiskFindingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 個々のリスク指摘。
 *
 * evidence（根拠となる記録ID・日付・該当箇所）を必須項目として扱うことで、
 * 根拠のない主張を構造的に禁止している。画面では根拠記録へのリンクを表示し、
 * 職員が原典を即座に確認できるようにする。
 *
 * source により、ルールベース由来（再現性あり）とLLM由来（要確認）を
 * 区別して表示する。
 *
 * @property int $id
 * @property int $risk_assessment_id
 * @property RiskCategory $category
 * @property RiskSeverity $severity
 * @property RiskSource $source
 * @property string $title
 * @property string $reason
 * @property array<int, array<string, mixed>>|null $evidence
 * @property array<int, string>|null $suggested_actions
 */
#[Fillable([
    'risk_assessment_id', 'category', 'severity', 'source',
    'title', 'reason', 'evidence', 'suggested_actions',
])]
class RiskFinding extends Model
{
    /** @use HasFactory<RiskFindingFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'category' => RiskCategory::class,
            'severity' => RiskSeverity::class,
            'source' => RiskSource::class,
            'evidence' => 'array',
            'suggested_actions' => 'array',
        ];
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeHighSeverity(Builder $query): Builder
    {
        return $query->where('severity', RiskSeverity::High);
    }

    /**
     * 再現性のある指摘だけを取り出す。
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeDeterministic(Builder $query): Builder
    {
        return $query->whereIn('source', [RiskSource::RuleBased, RiskSource::Both]);
    }

    /** 根拠が1件も添えられていない指摘。本来あってはならないため検知に使う。 */
    public function hasEvidence(): bool
    {
        return is_array($this->evidence) && $this->evidence !== [];
    }

    /**
     * @return BelongsTo<RiskAssessment, $this>
     */
    public function riskAssessment(): BelongsTo
    {
        return $this->belongsTo(RiskAssessment::class);
    }
}
