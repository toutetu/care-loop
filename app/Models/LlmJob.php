<?php

namespace App\Models;

use App\Enums\LlmFeature;
use App\Enums\LlmJobStatus;
use Carbon\CarbonInterface;
use Database\Factories\LlmJobFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Throwable;

/**
 * LLM実行ジョブ（F-LLM-06）。
 *
 * すべてのLLM呼び出しを非同期で実行するための単位。HTTPリクエスト内で
 * 同期実行すると、応答に数十秒かかる処理でタイムアウトとUXの両方が破綻する。
 *
 * 1ジョブが複数の llm_requests を持つ（リトライした分だけ増える）。
 *
 * @property int $id
 * @property LlmFeature $feature
 * @property string|null $target_type
 * @property int|null $target_id
 * @property CarbonInterface|null $period_from
 * @property CarbonInterface|null $period_to
 * @property LlmJobStatus $status
 * @property int|null $requested_by
 * @property array<string, mixed>|null $result
 * @property string|null $error_type
 * @property string|null $error_message
 * @property int $attempts
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $finished_at
 */
#[Fillable([
    'feature', 'target_type', 'target_id', 'period_from', 'period_to',
    'status', 'requested_by', 'result', 'error_type', 'error_message',
    'attempts', 'started_at', 'finished_at',
])]
class LlmJob extends Model
{
    /** @use HasFactory<LlmJobFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'feature' => LlmFeature::class,
            'status' => LlmJobStatus::class,
            'result' => 'array',
            'period_from' => 'date',
            'period_to' => 'date',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    // ---------------------------------------------------------------
    // スコープ
    // ---------------------------------------------------------------

    /**
     * 実行中または待機中。同一対象への重複実行を防ぐために使う。
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [LlmJobStatus::Queued, LlmJobStatus::Running]);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', LlmJobStatus::Failed);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForTarget(Builder $query, Model $target): Builder
    {
        return $query->where('target_type', $target::class)->where('target_id', $target->getKey());
    }

    // ---------------------------------------------------------------
    // 状態遷移
    // ---------------------------------------------------------------

    public function markRunning(): void
    {
        $this->forceFill([
            'status' => LlmJobStatus::Running,
            'started_at' => now(),
            'attempts' => $this->attempts + 1,
        ])->save();
    }

    /** @param array<string, mixed> $result */
    public function markSucceeded(array $result): void
    {
        $this->forceFill([
            'status' => LlmJobStatus::Succeeded,
            'result' => $result,
            'error_type' => null,
            'error_message' => null,
            'finished_at' => now(),
        ])->save();
    }

    /**
     * 失敗を記録する。error_type には rate_limit_error / schema_mismatch /
     * json_parse_error など、どの種類の失敗かを必ず入れる。
     * 画面での案内文と、再実行してよいかの判断がここで決まるため。
     */
    public function markFailed(string $errorType, string|Throwable $error): void
    {
        $this->forceFill([
            'status' => LlmJobStatus::Failed,
            'error_type' => $errorType,
            'error_message' => $error instanceof Throwable ? $error->getMessage() : $error,
            'finished_at' => now(),
        ])->save();
    }

    /** このジョブにかかった推定コスト（リトライ分を含む）。 */
    public function totalCostUsd(): float
    {
        return (float) $this->llmRequests()->sum('estimated_cost_usd');
    }

    public function durationSeconds(): ?float
    {
        if ($this->started_at === null || $this->finished_at === null) {
            return null;
        }

        return round($this->finished_at->getTimestampMs() - $this->started_at->getTimestampMs()) / 1000;
    }

    // ---------------------------------------------------------------
    // リレーション
    // ---------------------------------------------------------------

    /**
     * 対象（Resident / ServiceRecord など）。
     *
     * @return MorphTo<Model, $this>
     */
    public function target(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return HasMany<LlmRequest, $this>
     */
    public function llmRequests(): HasMany
    {
        return $this->hasMany(LlmRequest::class);
    }
}
