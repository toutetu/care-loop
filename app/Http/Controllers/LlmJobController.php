<?php

namespace App\Http\Controllers;

use App\Enums\LlmJobStatus;
use App\Models\LlmJob;
use App\Models\Resident;
use App\Models\ServiceRecord;
use App\Models\User;
use App\Support\QueueHeartbeat;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * AI処理の実行状況。
 *
 * 【AI利用ログとの違い】
 * こちらは「自分が動かしたAIがどうなったか」を見る画面で、職員全員が開ける。
 * AI利用ログ（LlmLogController）は費用とトークン数を扱う運営の画面で、
 * 管理者に限っている。
 *
 * 職員にとって必要なのは、押した処理が通ったのか失敗したのか、
 * 失敗したなら次に何をすればよいのかである。1件あたりの単価やキャッシュ率は
 * 日々の介護業務には要らない。
 *
 * 【事業所で絞る】
 * 実行者の所属で絞る。他の事業所の職員が何を実行したかは見えてはいけない。
 * 対象（target）はご利用者や記録を指すが、morph をたどるより
 * 実行者の所属を見るほうが確実で速い。
 */
class LlmJobController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $onlyFailed = $request->string('status')->value() === 'failed';

        $base = LlmJob::query()
            ->whereHas('requester', fn ($query) => $query->where('facility_id', $user->facility_id));

        $counts = [
            'total' => (clone $base)->count(),
            'succeeded' => (clone $base)->where('status', LlmJobStatus::Succeeded)->count(),
            // 見捨てたジョブは行の値が queued のままでも失敗として数える。
            // 一覧の表示（effectiveStatus）と件数が食い違ってはいけない。
            'failed' => (clone $base)->failed()->count() + (clone $base)->abandoned()->count(),
            // 動いている、または動く見込みのあるものだけ。見捨てたジョブまで
            // 数えると「実行中」が減らず、画面が読み直しを止められない。
            'running' => (clone $base)->blocking()->count(),
        ];

        $paginator = (clone $base)
            ->when($onlyFailed, fn ($query) => $query->where(
                fn ($inner) => $inner->failed()->orWhere(fn ($abandoned) => $abandoned->abandoned()),
            ))
            ->with(['requester', 'target'])
            ->latest('created_at')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('llm/jobs', [
            'counts' => $counts,
            'onlyFailed' => $onlyFailed,
            'canViewLlmLogs' => $user->role->canViewLlmLogs(),
            /*
             * ワーカーの生存。AI処理はキューで動くので、ワーカーが止まると
             * 待機中が増えるだけで何も起きない。最終稼働時刻を出しておけば、
             * 止まっていることに人が気づける。
             */
            'worker' => [
                'connection' => (string) config('queue.default'),
                'lastSeenAt' => QueueHeartbeat::lastSeenAt()?->translatedFormat('n月j日 H:i:s'),
            ],
            'jobs' => [
                'data' => array_values(collect($paginator->items())
                    ->map(fn (LlmJob $job): array => [
                        'id' => $job->id,
                        'feature' => $job->feature->label(),
                        'featureCode' => $job->feature->value,
                        // 見捨てたジョブは行の値が queued でも失敗として見せる
                        'status' => $job->effectiveStatus()->value,
                        'statusLabel' => $job->effectiveStatus()->label(),
                        // 待機が長引いている。ワーカーが止まっている疑い
                        'isDelayed' => $job->effectiveStatus()->isActive() && $job->isDelayed(),
                        'requester' => $job->requester?->name,
                        'attempts' => $job->attempts,
                        'durationSeconds' => $job->durationSeconds(),
                        'startedAt' => $job->started_at?->translatedFormat('n月j日 H:i'),
                        'requestedAt' => $job->created_at->translatedFormat('n月j日 H:i'),
                        ...$this->errorOf($job),
                        ...$this->targetOf($job),
                    ])->all()),
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * 失敗の内容。
     *
     * 職員に読ませるのは「次に何をすればよいか」であって、技術的な文言ではない。
     * 種別ごとの文面は LlmErrorType が持っているので、ここでは分岐しない。
     *
     * @return array<string, mixed>
     */
    private function errorOf(LlmJob $job): array
    {
        $type = $job->errorType();

        return [
            'errorLabel' => $type?->label(),
            'errorMessage' => $type !== null ? $job->userFacingError() : null,
            'isRetryable' => $type?->isRetryable() ?? false,
            'needsOperatorAttention' => $type?->needsOperatorAttention() ?? false,
        ];
    }

    /**
     * 何に対して実行したのか。
     *
     * 実行の一覧に対象が出ていないと、どの記録の話なのか分からない。
     * 対象が削除済みの場合もあるため、取得できないことを前提に組む。
     *
     * @return array<string, mixed>
     */
    private function targetOf(LlmJob $job): array
    {
        $target = $job->target;

        if ($target instanceof ServiceRecord) {
            return [
                'targetLabel' => sprintf(
                    '%s 様 ／ %s',
                    $target->resident->name ?? '（削除済み）',
                    $target->service_date->translatedFormat('n月j日'),
                ),
                'targetRecordId' => $target->id,
                'targetResidentId' => $target->resident_id,
            ];
        }

        if ($target instanceof Resident) {
            return [
                'targetLabel' => "{$target->name} 様",
                'targetRecordId' => null,
                'targetResidentId' => $target->id,
            ];
        }

        return ['targetLabel' => null, 'targetRecordId' => null, 'targetResidentId' => null];
    }
}
