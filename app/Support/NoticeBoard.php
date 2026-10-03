<?php

namespace App\Support;

use App\Models\Announcement;
use App\Models\RiskFinding;
use App\Models\User;
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

    /**
     * その職員の「お知らせ」の件数。事業所のお知らせに、本人宛てのものを足す。
     *
     * お知らせの画面に並ぶものと同じものを数える。画面と件数で数え方が
     * 違うと、バッジの数字を見て開いた職員が「どれのことか」と迷う。
     */
    public static function countFor(User $user): int
    {
        return (new self($user->facility_id))->count()
            // 自分宛ての個別の連絡は、読むまで数える
            + array_sum(array_column((new MessageInbox($user))->unreadDirect(), 'unread'))
            // 管理者からの周知は、「確認しました」を押すまで数える
            + Announcement::query()->unconfirmedBy($user)->count();
    }

    /**
     * その職員がまだ確認していない周知。重要なものを先に、新しい順に並べる。
     *
     * @return list<array<string, mixed>>
     */
    public static function unconfirmedAnnouncements(User $user): array
    {
        return array_values(Announcement::query()
            ->unconfirmedBy($user)
            ->with('author')
            ->orderByDesc('is_important')
            ->latest('id')
            ->get()
            ->map(fn (Announcement $announcement): array => [
                'id' => $announcement->id,
                'title' => $announcement->title,
                'body' => $announcement->body,
                'isImportant' => $announcement->is_important,
                'author' => $announcement->author !== null ? $announcement->author->name : '（退職した職員）',
                'postedAt' => $announcement->created_at->translatedFormat('n/j H:i'),
            ])
            ->all());
    }

    /** @return Builder<RiskFinding> */
    private function urgentRiskQuery(): Builder
    {
        return RiskFinding::query()
            ->highSeverity()
            ->whereHas(
                'riskAssessment',
                // 抽出を何度か実行すると、同じ指摘が回数ぶん並ぶ。
                // 利用者詳細と同じく、ご利用者ごとに最新の抽出だけを見る。
                fn ($query) => $query
                    ->latestPerResident()
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
