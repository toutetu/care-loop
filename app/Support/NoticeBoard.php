<?php

namespace App\Support;

use App\Models\RiskFinding;
use App\Models\VerbalContactTask;
use Illuminate\Database\Eloquent\Builder;

/**
 * 職員に「必ず目を通してほしい」ものを集める。
 *
 * 【ダッシュボードから切り出した理由】
 * 同じ内容を、PCではダッシュボードに、スマートフォンでは下のバーの
 * 「お知らせ」に出す。画面ごとに抽出条件を書くと、片方だけ直したときに
 * PCでは出るのにスマートフォンでは出ない、という食い違いが起きる。
 * 何を「お知らせ」とみなすかは、ここ1か所で決める。
 */
class NoticeBoard
{
    public function __construct(private readonly ?int $facilityId) {}

    /**
     * 職員がまだ確認していない、重要度の高い指摘。
     *
     * 確認済みのものは出さない。既読の指摘が並び続けると、
     * 新しい指摘が埋もれて誰も見なくなる。
     *
     * @return list<array<string, mixed>>
     */
    public function urgentRisks(): array
    {
        $findings = $this->urgentRiskQuery()
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
    public function pendingVerbalContacts(): array
    {
        $tasks = $this->verbalContactQuery()
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
     * 下のバーの「お知らせ」に付ける件数。
     *
     * 画面を開くたびに数えるので、中身は読まずに件数だけを取る。
     */
    public function count(): int
    {
        return $this->urgentRiskQuery()->count() + $this->verbalContactQuery()->count();
    }

    /** @return Builder<RiskFinding> */
    private function urgentRiskQuery(): Builder
    {
        return RiskFinding::query()
            ->highSeverity()
            ->whereHas(
                'riskAssessment',
                fn ($query) => $query
                    ->whereNull('reviewed_at')
                    ->whereHas('resident', fn ($inner) => $inner->where('facility_id', $this->facilityId)),
            );
    }

    /** @return Builder<VerbalContactTask> */
    private function verbalContactQuery(): Builder
    {
        return VerbalContactTask::query()
            ->pending()
            ->whereHas('resident', fn ($query) => $query->where('facility_id', $this->facilityId));
    }
}
