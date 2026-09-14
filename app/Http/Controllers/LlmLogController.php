<?php

namespace App\Http\Controllers;

use App\Enums\LlmErrorType;
use App\Enums\LlmFeature;
use App\Models\LlmRequest as LlmRequestLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * AI利用ログ（F-LLM-07）。
 *
 * 【なぜ画面にするのか】
 * LLMを組み込んだ機能は、動いているかどうかが外から見えない。
 * 失敗しても既定値が表示されるだけで、誰も気づかないまま使われ続ける。
 * 呼び出しの結果・所要時間・費用を残し、画面で見られるようにしておく。
 *
 * 【失敗を隠さない】
 * 成功したものだけを並べても、エラーハンドリングを作った意味が伝わらない。
 * 失敗したリクエストは種別ごとに集計して、別枠で出す。
 *
 * 【閲覧を管理者に限る】
 * 費用と失敗率は運営の情報であり、日々の介護業務には要らない
 * （UserRole::canViewLlmLogs）。
 */
class LlmLogController extends Controller
{
    private const PER_PAGE = 30;

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->role->canViewLlmLogs()) {
            throw new AccessDeniedHttpException('AI利用ログは管理者のみ参照できます。');
        }

        $month = now()->startOfMonth();

        /** @var Collection<int, LlmRequestLog> $monthly */
        $monthly = LlmRequestLog::query()
            ->where('created_at', '>=', $month)
            ->get();

        return Inertia::render('llm/index', [
            'summary' => $this->summary($monthly),
            'features' => $this->byFeature($monthly),
            'errors' => $this->byErrorType($monthly),
            'requests' => $this->requests($request),
        ]);
    }

    // ---------------------------------------------------------------

    /**
     * @param  Collection<int, LlmRequestLog>  $monthly
     * @return array<string, mixed>
     */
    private function summary(Collection $monthly): array
    {
        $budget = (float) config('llm.monthly_budget_usd');
        $spent = LlmRequestLog::monthlySpendUsd();
        $total = $monthly->count();
        $failed = $monthly->where('status', 'failed')->count();

        return [
            'month' => now()->translatedFormat('Y年n月'),
            'total' => $total,
            'failed' => $failed,
            'successRate' => $total > 0 ? round(($total - $failed) / $total, 3) : null,
            'spentUsd' => round($spent, 4),
            'budgetUsd' => $budget,
            'usageRate' => $budget > 0 ? min(1.0, round($spent / $budget, 4)) : 0.0,
            // プロンプトの前半（システムプロンプト）が安定していれば、
            // キャッシュが効いて入力の費用が下がる。設計の効きを数字で見る。
            'cacheHitRate' => round(LlmRequestLog::cacheHitRate(), 3),
            'promptVersion' => (string) config('llm.prompt_version'),
            'defaultModel' => (string) config('llm.default_model'),
            'driver' => config('llm.api_key') !== null && config('llm.api_key') !== ''
                ? (string) config('llm.driver')
                : 'fake',
        ];
    }

    /**
     * 機能ごとの内訳。どの機能が費用を使っているかを見る。
     *
     * @param  Collection<int, LlmRequestLog>  $monthly
     * @return list<array<string, mixed>>
     */
    private function byFeature(Collection $monthly): array
    {
        $rows = [];

        foreach (LlmFeature::cases() as $feature) {
            $requests = $monthly->where('feature', $feature);

            if ($requests->isEmpty()) {
                continue;
            }

            $failed = $requests->where('status', 'failed')->count();

            $rows[] = [
                'code' => $feature->value,
                'label' => $feature->label(),
                // 機能ごとにモデルを変えられる設計なので、実際に何で動いたのかを出す。
                // 設定を変えた直後は、反映されたかどうかがここでしか分からない。
                // 期間内に切り替えた場合は複数並ぶ。
                'models' => $requests->pluck('model')->unique()->sort()->values()->all(),
                'count' => $requests->count(),
                'failed' => $failed,
                'inputTokens' => (int) $requests->sum('input_tokens'),
                'outputTokens' => (int) $requests->sum('output_tokens'),
                'costUsd' => round((float) $requests->sum('estimated_cost_usd'), 4),
                'avgLatencyMs' => (int) round((float) $requests->avg('latency_ms')),
            ];
        }

        usort($rows, fn (array $a, array $b) => $b['count'] <=> $a['count']);

        return $rows;
    }

    /**
     * 失敗の種別ごとの件数。
     *
     * リトライしてよい失敗か、設定を直すべき失敗かを区別して出す。
     * 「混み合っている」と「APIキーが違う」は、担当者の取るべき行動が違う。
     *
     * @param  Collection<int, LlmRequestLog>  $monthly
     * @return list<array<string, mixed>>
     */
    private function byErrorType(Collection $monthly): array
    {
        $rows = [];

        foreach ($monthly->where('status', 'failed')->groupBy('error_type') as $type => $requests) {
            $errorType = is_string($type) ? LlmErrorType::tryFrom($type) : null;

            $rows[] = [
                'type' => is_string($type) ? $type : 'unknown',
                'label' => $errorType?->label() ?? '不明',
                'count' => $requests->count(),
                'isRetryable' => $errorType?->isRetryable() ?? false,
                'needsOperatorAttention' => $errorType?->needsOperatorAttention() ?? true,
                'userMessage' => $errorType?->userMessage(),
            ];
        }

        usort($rows, fn (array $a, array $b) => $b['count'] <=> $a['count']);

        return $rows;
    }

    /**
     * 呼び出しの明細。
     *
     * @return array<string, mixed>
     */
    private function requests(Request $request): array
    {
        $paginator = LlmRequestLog::query()
            ->with('llmJob.requester')
            ->latest('created_at')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return [
            'data' => collect($paginator->items())
                ->map(fn (LlmRequestLog $log): array => [
                    'id' => $log->id,
                    'feature' => $log->feature->label(),
                    'model' => $log->model,
                    'status' => $log->status,
                    'errorLabel' => $log->error_type !== null
                        ? LlmErrorType::tryFrom($log->error_type)?->label()
                        : null,
                    'inputTokens' => $log->input_tokens,
                    'outputTokens' => $log->output_tokens,
                    'cachedTokens' => $log->cache_read_input_tokens,
                    'latencyMs' => $log->latency_ms,
                    'retryCount' => $log->retry_count,
                    'costUsd' => (float) $log->estimated_cost_usd,
                    'requester' => $log->llmJob?->requester?->name,
                    'createdAt' => $log->created_at?->translatedFormat('n/j H:i'),
                ])->all(),
            'currentPage' => $paginator->currentPage(),
            'lastPage' => $paginator->lastPage(),
            'total' => $paginator->total(),
        ];
    }
}
