<?php

namespace App\Models;

use App\Enums\LlmFeature;
use Database\Factories\LlmRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * LLM呼び出しの監査ログ（F-LLM-07）。API呼び出し1回につき1行。
 *
 * masked_request / masked_response にはマスキング後の内容だけを保存する。
 * ログ経由で個人情報が二次流出することを防ぐため（要件定義 7.2節）。
 *
 * @property int $id
 * @property int|null $llm_job_id
 * @property LlmFeature $feature
 * @property string $model
 * @property string|null $prompt_version
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $cache_read_input_tokens
 * @property int $latency_ms
 * @property string $status
 * @property string|null $error_type
 * @property int $retry_count
 * @property string|null $stop_reason
 * @property float $estimated_cost_usd
 */
#[Fillable([
    'llm_job_id', 'feature', 'model', 'prompt_version',
    'input_tokens', 'output_tokens', 'cache_read_input_tokens', 'cache_creation_input_tokens',
    'latency_ms', 'status', 'error_type', 'retry_count', 'stop_reason',
    'estimated_cost_usd', 'masked_request', 'masked_response',
])]
class LlmRequest extends Model
{
    /** @use HasFactory<LlmRequestFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'feature' => LlmFeature::class,
            'estimated_cost_usd' => 'float',
        ];
    }

    /**
     * 実行時点の単価でコストを計算する。
     *
     * 料金は改定されうるため、あとからトークン数を掛け直しても当時の実費は
     * 再現できない。だから計算結果を保存する（意図的な非正規化）。
     * 単位は 100万トークンあたりの米ドル。
     */
    public static function calculateCostUsd(string $model, int $inputTokens, int $outputTokens): float
    {
        $pricing = config("llm.pricing.{$model}");

        if (! is_array($pricing)) {
            return 0.0;
        }

        return round(
            ($inputTokens / 1_000_000) * (float) $pricing['input']
            + ($outputTokens / 1_000_000) * (float) $pricing['output'],
            6
        );
    }

    /** 指定月の合計コスト。月次予算の上限判定に使う。 */
    public static function monthlySpendUsd(?Carbon $month = null): float
    {
        $month ??= now();

        return (float) static::query()
            ->whereBetween('created_at', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()])
            ->sum('estimated_cost_usd');
    }

    /**
     * プロンプトキャッシュのヒット率。
     * これが 0 のまま推移するときは、システムプロンプトに毎回変わる値
     * （日時など）が混ざっていてキャッシュが効いていない可能性が高い。
     */
    public static function cacheHitRate(?Carbon $month = null): float
    {
        $month ??= now();
        $range = [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()];

        $total = (int) static::query()->whereBetween('created_at', $range)->sum('input_tokens');
        $cached = (int) static::query()->whereBetween('created_at', $range)->sum('cache_read_input_tokens');

        return $total > 0 ? round($cached / $total, 4) : 0.0;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', 'failed');
    }

    /**
     * @return BelongsTo<LlmJob, $this>
     */
    public function llmJob(): BelongsTo
    {
        return $this->belongsTo(LlmJob::class);
    }
}
