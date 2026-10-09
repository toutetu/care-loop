<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\LlmJob;
use App\Models\Resident;
use App\Models\ServiceRecord;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 保存期間を過ぎたご利用者の情報と記録を物理削除する。毎日3時に動かす。
 *
 * 【なぜ消すか】
 * 業務の操作では記録を消さず、論理削除にとどめる（法定文書が職員の操作で
 * 消えてはならない）。その代わり、保存期間を過ぎたものは持ち続けない。
 * 持っていない情報は漏れない（要件定義 9.3.2節 施策6）。
 *
 * 【起点は利用終了日】
 * 保存期間は「完結の日」から数える。これを記録1件ごとの日付とみるか、
 * 契約の終了日とみるかは解釈が分かれる。記録ごとに消すと、契約の終了日と
 * みる自治体では保存期間の途中で消すことになる。消したものは戻せないので、
 * 遅いほう（利用終了日）に倒す。利用終了日のない方は、いつ完結したのかが
 * 分からないので消さない。
 *
 * 【外部キーでつながらないものも消す】
 * 記録・計画書・AIの評価・閲覧履歴は、ご利用者を消せば外部キーで一緒に消える。
 * 編集履歴（旧住所などを含む）とAI処理の実行記録は対象を型とIDで指しており、
 * 外部キーがないので、ここで明示的に消す。氏名を伏せて送った llm_requests は
 * 費用の履歴として残す（実行記録との紐づけだけが外れる）。
 *
 * 【消す前に件数を残す】
 * 誤って消したときに、何がどれだけ消えたのかを後から辿れるようにする。
 * 手で動かすときは --dry-run で件数だけを先に確かめる。
 */
class PurgeExpiredRecords extends Command
{
    protected $signature = 'careloop:purge-expired-records {--dry-run : 消さずに件数だけを表示する}';

    protected $description = '利用終了日から保存期間を過ぎたご利用者の情報と記録を物理削除します';

    public function handle(): int
    {
        $years = (int) config('careloop.record_retention_years');
        $cutoff = today()->subYears($years);

        $residentIds = Resident::withTrashed()
            ->whereNotNull('ended_at')
            ->where('ended_at', '<', $cutoff)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $recordCount = ServiceRecord::withTrashed()->whereIn('resident_id', $residentIds)->count();

        $summary = sprintf(
            '利用終了日が %s より前のご利用者は %d 名、記録は %d 件です。',
            $cutoff->toDateString(),
            count($residentIds),
            $recordCount,
        );

        if ($this->option('dry-run')) {
            $this->components->info($summary.'--dry-run のため削除していません。');

            return self::SUCCESS;
        }

        Log::info("保存期間（{$years}年）を過ぎた記録を物理削除します。", [
            'cutoff' => $cutoff->toDateString(),
            'residents' => count($residentIds),
            'service_records' => $recordCount,
            'resident_ids' => $residentIds,
        ]);

        // 1人ずつ区切る。途中で失敗しても、消し終えた方と手つかずの方に分かれ、
        // 1人の記録の一部だけが消えた状態は残らない。
        foreach ($residentIds as $residentId) {
            DB::transaction(fn () => $this->purge($residentId));
        }

        $this->components->info($summary.'削除しました。');

        return self::SUCCESS;
    }

    private function purge(int $residentId): void
    {
        $residentType = (new Resident)->getMorphClass();
        $recordType = (new ServiceRecord)->getMorphClass();
        $recordIds = ServiceRecord::withTrashed()->where('resident_id', $residentId)->pluck('id');

        AuditLog::query()
            ->where('auditable_type', $residentType)
            ->where('auditable_id', $residentId)
            ->delete();

        LlmJob::query()
            ->where(fn ($query) => $query
                ->where('target_type', $residentType)
                ->where('target_id', $residentId))
            ->orWhere(fn ($query) => $query
                ->where('target_type', $recordType)
                ->whereIn('target_id', $recordIds))
            ->delete();

        Resident::withTrashed()->whereKey($residentId)->forceDelete();
    }
}
