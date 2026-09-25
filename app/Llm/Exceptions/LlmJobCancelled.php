<?php

namespace App\Llm\Exceptions;

use RuntimeException;

/**
 * 職員がAI処理を中止した。
 *
 * 【LlmException と分ける】
 * LlmException は「失敗」で、種別ごとに再送するか・何と案内するかを決める。
 * 中止は失敗ではない。再送もしないし、失敗として記録もしない。
 * 同じ例外に入れると、中止が失敗の件数に数えられる。
 *
 * 投げるのは、送信の直前（LlmGateway）と、結果を記録へ書き込む直前
 * （LlmJob::claimCompletion）の2か所。どちらでも、まだ何も書き換えていない。
 */
final class LlmJobCancelled extends RuntimeException
{
    public static function forJob(int $jobId): self
    {
        return new self("AI処理（ジョブ {$jobId}）は中止されたため、結果を反映しません。");
    }
}
