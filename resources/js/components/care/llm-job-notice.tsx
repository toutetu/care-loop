import { Loader2, TriangleAlert } from 'lucide-react';
import { NOTICE_SURFACE } from '@/lib/care-presentation';
import { cn } from '@/lib/utils';
import type { LlmJobSummary } from '@/types/care';

/**
 * AI処理が動いているあいだの案内。
 *
 * 押したあと何も変わらない数十秒のあいだに、職員がもう一度押したり
 * 画面を離れたりしないよう、動いていることと待てばよいことを伝える。
 *
 * 待機が長引いているときは色を変える。ワーカーが止まっていると、
 * 待機中のまま何も起きない。「待てば終わる」と「待っても終わらない」を
 * 同じ見た目にしてはいけない。
 */
export function LlmJobNotice({
    job,
    className,
}: {
    job: LlmJobSummary | null;
    className?: string;
}) {
    if (job === null || !job.isActive) {
        return null;
    }

    if (job.isDelayed) {
        return (
            <p
                role="status"
                className={cn(
                    'flex items-start gap-2 rounded-md border px-3 py-2 text-sm',
                    NOTICE_SURFACE.warning,
                    className,
                )}
            >
                <TriangleAlert className="mt-0.5 size-4 shrink-0" aria-hidden />
                <span>
                    まだ処理が始まっていません（{job.waitingSeconds ?? 0}
                    秒経過）。処理する仕組みが止まっている可能性があります。
                    しばらく待っても変わらないときは、管理者にお知らせください。
                </span>
            </p>
        );
    }

    return (
        <p
            role="status"
            className={cn(
                'text-muted-foreground flex items-start gap-2 text-sm',
                className,
            )}
        >
            <Loader2
                className="mt-0.5 size-4 shrink-0 animate-spin"
                aria-hidden
            />
            <span>
                {job.statusLabel}です。完了すると自動で表示が更新されます。
                この画面を開いたままお待ちください。
            </span>
        </p>
    );
}
