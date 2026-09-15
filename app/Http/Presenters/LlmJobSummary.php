<?php

namespace App\Http\Presenters;

use App\Enums\LlmFeature;
use App\Enums\LlmJobStatus;
use App\Models\LlmJob;
use Illuminate\Database\Eloquent\Model;

/**
 * 画面が進捗表示に使う、ジョブ1件の要約。
 *
 * ご利用者の詳細と記録の入力の両方が同じ形を必要とする。それぞれの
 * コントローラで組み立てると、片方だけ項目が増えて食い違う。
 *
 * 画面側の型は resources/js/types/care.ts の LlmJobSummary。
 */
final class LlmJobSummary
{
    /**
     * @return array<string, mixed>|null
     */
    public static function of(?LlmJob $job): ?array
    {
        if ($job === null) {
            return null;
        }

        // 見捨てたジョブは行の値が queued でも失敗として見せる
        $status = $job->effectiveStatus();

        return [
            'id' => $job->id,
            'feature' => $job->feature->value,
            'status' => $status->value,
            'statusLabel' => $status->label(),
            'isActive' => $status->isActive(),
            // 待機が長引いている。画面は「処理が始まっていません」と出す
            'isDelayed' => $status->isActive() && $job->isDelayed(),
            'waitingSeconds' => $status->isActive() ? $job->waitingSeconds() : null,
            'completedMessage' => $status === LlmJobStatus::Succeeded ? $job->feature->completedMessage() : null,
            'errorMessage' => $status === LlmJobStatus::Failed ? $job->userFacingError() : null,
            'needsOperatorAttention' => $job->errorType()?->needsOperatorAttention() ?? false,
            'finishedAt' => $job->finished_at?->translatedFormat('n月j日 H:i'),
        ];
    }

    /**
     * 対象と機能の組み合わせで、いちばん新しい1件。
     *
     * 実行中なら進捗の表示に、終わっていれば完了・失敗の通知に使う。
     * 画面は前回見た状態と比べて、変わったときだけ通知を出す。
     *
     * @return array<string, mixed>|null
     */
    public static function latestFor(Model $target, LlmFeature $feature): ?array
    {
        return self::of(
            LlmJob::query()
                ->forTarget($target)
                ->where('feature', $feature)
                ->latest('id')
                ->first(),
        );
    }
}
