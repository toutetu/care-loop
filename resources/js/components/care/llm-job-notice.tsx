import { Form } from '@inertiajs/react';
import { CircleStop, Loader2, TriangleAlert } from 'lucide-react';
import LlmActionController from '@/actions/App/Http/Controllers/LlmActionController';
import { Button } from '@/components/ui/button';
import { NOTICE_SURFACE } from '@/lib/care-presentation';
import { cn } from '@/lib/utils';
import type { LlmJobSummary } from '@/types/care';

/**
 * AI処理が動いているあいだの案内と、中止のボタン。
 *
 * 押したあと何も変わらない数十秒のあいだに、職員がもう一度押したり
 * 画面を離れたりしないよう、動いていることと待てばよいことを伝える。
 *
 * 待機が長引いているときは色を変える。ワーカーが止まっていると、
 * 待機中のまま何も起きない。「待てば終わる」と「待っても終わらない」を
 * 同じ見た目にしてはいけない。
 *
 * 【中止をここに置く】
 * 押し間違いに気づくのは、押した直後にこの案内が出たときである。
 * 案内の隣に置けば、画面を探さずに止められる。待っても始まらないときに
 * 手で書くことへ切り替える出口にもなる。
 *
 * @param cancellable 中止のボタンを出すか。AIを実行できる職員にだけ渡す
 */
export function LlmJobNotice({
    job,
    cancellable = false,
    className,
}: {
    job: LlmJobSummary | null;
    cancellable?: boolean;
    className?: string;
}) {
    if (job === null || !job.isActive) {
        return null;
    }

    const cancel = cancellable ? <CancelButton jobId={job.id} /> : null;

    if (job.isDelayed) {
        return (
            <div
                className={cn(
                    'flex flex-wrap items-center justify-between gap-3 rounded-md border px-3 py-2',
                    NOTICE_SURFACE.warning,
                    className,
                )}
            >
                <p role="status" className="flex items-start gap-2 text-sm">
                    <TriangleAlert
                        className="mt-0.5 size-4 shrink-0"
                        aria-hidden
                    />
                    <span>
                        まだ処理が始まっていません（{job.waitingSeconds ?? 0}
                        秒経過）。処理する仕組みが止まっている可能性があります。
                        しばらく待っても変わらないときは、管理者にお知らせください。
                    </span>
                </p>
                {cancel}
            </div>
        );
    }

    return (
        <div
            className={cn(
                'flex flex-wrap items-center justify-between gap-3',
                className,
            )}
        >
            <p
                role="status"
                className="text-muted-foreground flex min-w-0 flex-1 basis-64 items-start gap-2 text-sm"
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
            {cancel}
        </div>
    );
}

/**
 * 中止のボタン。
 *
 * 確かめのダイアログは挟まない。中止しても何も失われず（結果を書き込まない
 * だけで、押し直せば同じ処理をもう一度頼める）、止めたい場面では1秒でも
 * 早いほうがよい。見た目は取り消し用の neutral にそろえる。
 */
function CancelButton({ jobId }: { jobId: number }) {
    return (
        <Form
            {...LlmActionController.cancel.form(jobId)}
            options={{ preserveScroll: true }}
            className="shrink-0"
        >
            {({ processing }) => (
                <Button
                    type="submit"
                    variant="neutral"
                    size="sm"
                    pending={processing}
                >
                    {!processing && (
                        <CircleStop className="size-4" aria-hidden />
                    )}
                    {processing ? '中止しています…' : '中止する'}
                </Button>
            )}
        </Form>
    );
}
