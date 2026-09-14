<?php

namespace App\Http\Controllers;

use App\Enums\LlmErrorType;
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
            'attendance' => $this->attendance($user, $date),
            'risks' => $this->urgentRisks($facilityId),
            'verbalContacts' => $this->pendingVerbalContacts($facilityId),
            'llm' => $this->llmStatus(),
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
     * その日のご利用者と、記録の入力状況。
     *
     * 【確定済みかどうかを一覧に出す】
     * 記録は法定の保存文書であり、書き忘れたまま日をまたぐと、後から
     * 思い出して書くことになる。その日のうちに気づける形にしておく。
     *
     * 【並べ替えをPHP側で行っている理由】
     * 氏名カナは暗号化して保存しているため、SQLでは並べ替えられない
     * （要件定義 9.3節）。1日のご利用者は定員以下に収まるので、
     * 取得してから並べても問題にならない。
     *
     * @return list<array<string, mixed>>
     */
    private function attendance(User $user, CarbonInterface $date): array
    {
        $records = ServiceRecord::query()
            ->whereHas('resident', fn ($query) => $query->where('facility_id', $user->facility_id))
            ->whereDate('service_date', $date)
            ->with(['resident.careLevel', 'recorder', 'vitalSigns'])
            ->get()
            ->sortBy(fn (ServiceRecord $record) => $record->resident->name_kana)
            ->values();

        // array_values で添字を振り直す。連番でない配列はJSONにするとオブジェクトに
        // なり、画面側で配列として扱えなくなる。
        return array_values($records->map(function (ServiceRecord $record) use ($user): array {
            $vital = $record->vitalSigns->first();

            return [
                'recordId' => $record->id,
                'residentId' => $record->resident_id,
                'name' => $record->resident->name,
                'careLevel' => $record->resident->careLevel?->name,
                'arrivalTime' => $this->hhmm($record->arrival_time),
                'departureTime' => $this->hhmm($record->departure_time),
                'temperature' => $vital?->temperature,
                'recorder' => $record->recorder?->name,
                // 確定済み・確定前・AI下書きのまま の3状態を出し分ける。
                // AIが書いた文章が未確認のまま残っている状態を見逃さないため。
                'status' => match (true) {
                    $record->hasUnconfirmedAiDraft() => 'ai_draft',
                    $record->isConfirmed() => 'confirmed',
                    default => 'draft',
                },
                'bathing' => $record->bathing_performed,
                // 一般職員は自分が記録したものだけ編集できる（ServiceRecordPolicy）。
                // 押せないボタンを並べても、403になるまで分からない。
                'canEdit' => $user->can('update', $record),
            ];
        })->all());
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
     * 【費用を職員の見える場所に出す】
     * 1回あたりの単価が小さいと、呼び出し放題という誤解が生まれる。
     * 上限に対して今どこまで使っているのかを、常に見せておく。
     *
     * @return array<string, mixed>
     */
    private function llmStatus(): array
    {
        $jobs = LlmJob::query()
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
            // キャッシュが効いていることは、プロンプトの前半が安定している証拠でもある
            'cacheHitRate' => round(LlmRequest::cacheHitRate(), 3),
            'recent' => $this->recentJobs(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function recentJobs(): array
    {
        $jobs = LlmJob::query()
            ->with('requester')
            ->latest('created_at')
            ->limit(5)
            ->get();

        return array_values($jobs->map(fn (LlmJob $job): array => [
            'id' => $job->id,
            'feature' => $job->feature->label(),
            'status' => $job->status->value,
            'statusLabel' => $job->status->label(),
            'requester' => $job->requester?->name,
            'errorLabel' => $job->error_type !== null
                ? LlmErrorType::tryFrom($job->error_type)?->label()
                : null,
            'finishedAt' => $job->finished_at?->translatedFormat('n/j H:i'),
        ])->all());
    }

    /**
     * time 型の列は "09:30:00" の文字列で返る。画面では秒まで要らない。
     */
    private function hhmm(?string $time): ?string
    {
        return $time !== null ? substr($time, 0, 5) : null;
    }
}
