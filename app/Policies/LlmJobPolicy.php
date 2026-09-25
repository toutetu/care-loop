<?php

namespace App\Policies;

use App\Models\LlmJob;
use App\Models\Resident;
use App\Models\ServiceRecord;
use App\Models\User;

/**
 * AI処理のジョブの権限。
 *
 * ジョブそのものに権限を持たせず、対象（記録・ご利用者）の権限に従う。
 * 対象に対してAIを実行できる職員なら、同じジョブを中止もできる。
 */
class LlmJobPolicy
{
    /**
     * 中止。
     *
     * 【押した本人に限らない】
     * 押し間違いに気づくのは、押した本人とは限らない。同じ記録を開いた職員が、
     * 自分の直した文章が書き直されそうだと気づくこともある。実行できる人は
     * 中止もできる、とそろえる。事業所の境界は対象の権限がそのまま守る。
     *
     * 対象が削除されていれば、誰も中止できない。削除された対象への実行は、
     * ワーカーが前提不足として失敗させる。
     */
    public function cancel(User $user, LlmJob $job): bool
    {
        $target = $job->target;

        return match (true) {
            $target instanceof ServiceRecord => $user->can('update', $target),
            $target instanceof Resident => $user->can('runLlm', $target),
            default => false,
        };
    }
}
