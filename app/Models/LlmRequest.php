<?php

namespace App\Models;

use App\Enums\LlmFeature;
use Carbon\CarbonInterface;
use Database\Factories\LlmRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

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
            // 0 を返してはいけない。「値段が分からない」ことと「無料」は違う。
            // 0 として記録すると、課金は発生しているのに合計が増えず、
            // 月次上限が永久に発動しない。費用管理としては最悪の壊れ方になる。
            throw new InvalidArgumentException(
                "モデル {$model} の単価が config/llm.php の pricing にありません。"
                .'単価が分からないと費用を算出できず、月次上限の判定も効かなくなります。'
            );
        }

        return round(
            ($inputTokens / 1_000_000) * (float) $pricing['input']
            + ($outputTokens / 1_000_000) * (float) $pricing['output'],
            6
        );
    }

    /**
     * 単価が分かっているモデルか。
     *
     * APIを呼ぶ前の確認に使う。呼んでしまってから「値段が分からない」と
     * 気づいても、課金はすでに発生している。
     */
    public static function hasPricing(string $model): bool
    {
        return is_array(config("llm.pricing.{$model}"));
    }

    /** 指定月の合計コスト。月次予算の上限判定に使う。 */
    public static function monthlySpendUsd(?CarbonInterface $month = null): float
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
    public static function cacheHitRate(?CarbonInterface $month = null): float
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
