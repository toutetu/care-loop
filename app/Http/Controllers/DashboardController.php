<?php

namespace App\Http\Controllers;

use App\Enums\LlmJobStatus;
use App\Models\LlmJob;
use App\Models\LlmRequest;
use App\Models\RiskFinding;
use App\Models\ServiceRecord;
use App\Models\User;
use App\Models\VerbalContactTask;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ダッシュボード。朝礼と申し送りの場面で開く画面。
 *
 * 【並び順の考え方】
 * 「本日のご利用者」を最上段に置く。出勤してまず知りたいのは、
 * 今日どなたが来られるのかと、記録が未入力のまま残っていないかである。
 * リスクやAIの実行状況はその次でよい。
 */
class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $facilityId = $user->facility_id;

        $date = $this->targetDate($facilityId);

        return Inertia::render('dashboard', [
            'day' => [
                'date' => $date->toDateString(),
                'label' => $date->translatedFormat('n月j日（D）'),
                'isToday' => $date->isToday(),
            ],
            // 一覧そのものは記録一覧の画面が持つ。ここは件数だけを出し、
            // 中身を見たい人はその画面へ送る。朝礼で全体を見る場所と、
            // 記録を埋めていく場所は目的が違う。
            'counts' => $this->recordCounts($facilityId, $date),
            'risks' => $this->urgentRisks($facilityId),
            'verbalContacts' => $this->pendingVerbalContacts($facilityId),
            'llm' => $this->llmStatus($facilityId),
            'canViewLlmLogs' => $user->role->canViewLlmLogs(),
        ]);
    }

    /**
     * 表示する日付を決める。
     *
     * 【本日に記録がなければ直近の利用日へ下がる】
     * 通所介護は日曜や祝日に営業しない事業所が多い。休業日に開いて一覧が
     * 空になると、画面が壊れているのか休みなのか区別がつかない。
     * 実際に記録のある直近の日へ下げ、日付を明示して表示する。
     */
    private function targetDate(?int $facilityId): CarbonInterface
    {
        $today = today();

        $exists = ServiceRecord::query()
            ->whereHas('resident', fn ($query) => $query->where('facility_id', $facilityId))
            ->whereDate('service_date', $today)
            ->exists();

        if ($exists) {
            return $today;
        }

        $latest = ServiceRecord::query()
            ->whereHas('resident', fn ($query) => $query->where('facility_id', $facilityId))
            ->max('service_date');

        return is_string($latest) ? Date::parse($latest) : $today;
    }

    /**
     * その日の記録の件数。
     *
     * 【一覧ではなく件数だけを持つ】
     * 一覧は記録一覧の画面が持つ。ダッシュボードは朝礼で全体を見る場所で、
     * 「何件残っているか」が分かれば足りる。中身を見たい人はその画面へ移る。
     *
     * @return array<string, int>
     */
    private function recordCounts(?int $facilityId, CarbonInterface $date): array
    {
        $onDate = fn () => ServiceRecord::query()
            ->whereHas('resident', fn ($query) => $query->where('facility_id', $facilityId))
            ->whereDate('service_date', $date);

        return [
            'residents' => $onDate()->count(),
            // 記録は法定の保存文書であり、書き忘れたまま日をまたぐと
            // 後から思い出して書くことになる。その日のうちに気づける形にする。
            'unconfirmed' => $onDate()->whereNull('confirmed_at')->count(),
        ];
    }

    /**
     * 職員がまだ確認していない、重要度の高い指摘。
     *
     * 確認済みのものは出さない。既読の指摘が並び続けると、
     * 新しい指摘が埋もれて誰も見なくなる。
     *
     * @return list<array<string, mixed>>
     */
    private function urgentRisks(?int $facilityId): array
    {
        $findings = RiskFinding::query()
            ->highSeverity()
            ->whereHas(
                'riskAssessment',
                fn ($query) => $query
                    ->whereNull('reviewed_at')
                    ->whereHas('resident', fn ($inner) => $inner->where('facility_id', $facilityId)),
            )
            ->with('riskAssessment.resident')
            ->get();

        return array_values($findings->map(fn (RiskFinding $finding): array => [
            'id' => $finding->id,
            'residentId' => $finding->riskAssessment->resident_id,
            'residentName' => $finding->riskAssessment->resident->name,
            'title' => $finding->title,
            'category' => $finding->category->label(),
            'severityLabel' => $finding->severity->label(),
            'source' => $finding->source->value,
            'sourceLabel' => $finding->source->label(),
            // 再現性のある指摘か、読んで判断すべき指摘かを画面で区別する
            // （要件定義 7.1節）。ここがこのアプリの設計上の要点にあたる。
            'isDeterministic' => $finding->source->isDeterministic(),
            'evidenceCount' => is_array($finding->evidence) ? count($finding->evidence) : 0,
            'assessedOn' => $finding->riskAssessment->assessed_at->translatedFormat('n月j日'),
        ])->all());
    }

    /**
     * まだお伝えできていない口頭連絡（F-20）。
     *
     * 連絡帳に書いてあるからといって、伝わったことにはならない。
     * 送迎の担当者が代わっても引き継がれるよう、画面に残す。
     *
     * @return list<array<string, mixed>>
     */
    private function pendingVerbalContacts(?int $facilityId): array
    {
        $tasks = VerbalContactTask::query()
            ->pending()
            ->whereHas('resident', fn ($query) => $query->where('facility_id', $facilityId))
            ->with('resident')
            ->get();

        return array_values($tasks->map(fn (VerbalContactTask $task): array => [
            'id' => $task->id,
            'residentId' => $task->resident_id,
            'residentName' => $task->resident->name,
            'topic' => $task->topic,
            'urgencyLabel' => $task->urgency === 'same_day' ? '当日中' : '次回利用時',
            'recordId' => $task->service_record_id,
        ])->all());
    }

    /**
     * AIの実行状況と、今月の費用。
     *
     * 【一覧ではなく件数だけを持つ】
     * 実行の明細は「AI処理の実行状況」の画面が持つ。
     *
     * 【費用を職員の見える場所に出す】
     * 1回あたりの単価が小さいと、呼び出し放題という誤解が生まれる。
     * 上限に対して今どこまで使っているのかを、常に見せておく。
     *
     * @return array<string, mixed>
     */
    private function llmStatus(?int $facilityId): array
    {
        $jobs = LlmJob::query()
            ->whereHas('requester', fn ($query) => $query->where('facility_id', $facilityId))
            ->where('created_at', '>=', now()->startOfMonth())
            ->get();

        $budget = (float) config('llm.monthly_budget_usd');
        $spent = LlmRequest::monthlySpendUsd();

        return [
            'succeeded' => $jobs->where('status', LlmJobStatus::Succeeded)->count(),
            'failed' => $jobs->where('status', LlmJobStatus::Failed)->count(),
            'spentUsd' => round($spent, 4),
            'budgetUsd' => $budget,
            'usageRate' => $budget > 0 ? min(1.0, round($spent / $budget, 4)) : 0.0,
        ];
    }
}
