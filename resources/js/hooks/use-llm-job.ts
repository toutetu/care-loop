import { router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { toast } from 'sonner';
import type { LlmJobSummary } from '@/types/care';

/** 読み直す間隔。AI処理は10秒前後かかるので、これより細かくしても意味がない。 */
const POLL_INTERVAL_MS = 3000;

/**
 * AI処理の進捗を追い、終わったら知らせる。
 *
 * 【押した画面で完結させる】
 * AI処理はキューで動くため、押した時点では結果がない。実行中のあいだだけ
 * 必要な項目を読み直し、終わった時点で結果が画面に現れる。
 * 職員を「AI処理の実行状況」の画面へ移動させて確認させる作りにはしない。
 *
 * 【通知は変化したときだけ】
 * 完了・失敗の文言はサーバーから届く。ただし画面を開くたびに前回の結果を
 * 通知しては煩い。この画面を開いてから終わったジョブだけを知らせる。
 * 受け付けた次の瞬間にはもう終わっていることもある（APIキーなしのデモ
 * モードは一瞬で返る）ので、「実行中を見たかどうか」ではなく
 * 「前回見たときと同じ終わったジョブかどうか」で判断する。
 *
 * @param job 対象と機能の組み合わせで最新のジョブ。無ければ null
 * @param only 実行中に読み直す props の名前。結果が入る項目と job 自身を含める
 * @param scope 対象の識別子。Inertia は別のご利用者へ移っても同じ画面部品を
 *              使い回すので、これが変わったら前回の状態を捨てる
 */
export function useLlmJobPolling(
    job: LlmJobSummary | null,
    only: readonly string[],
    scope: string,
): void {
    const previous = useRef<{ scope: string; job: LlmJobSummary | null }>({
        scope,
        job,
    });

    useEffect(() => {
        const before = previous.current;
        previous.current = { scope, job };

        if (before.scope !== scope || job === null || job.isActive) {
            return;
        }

        // 前回見たときも同じジョブが終わっていたなら、何も起きていない
        const unchanged =
            before.job !== null &&
            before.job.id === job.id &&
            !before.job.isActive;

        if (unchanged) {
            return;
        }

        if (job.status === 'succeeded' && job.completedMessage) {
            toast.success(job.completedMessage);
            return;
        }

        if (job.errorMessage) {
            // 失敗は自動で消さない。「もう一度押す」と「管理者に連絡する」では
            // 取るべき行動が違うので、読む前に消えては意味がない。
            toast.error(job.errorMessage, {
                duration: Infinity,
                closeButton: true,
            });
        }
    }, [job, scope]);

    const isActive = job?.isActive ?? false;
    // 配列は毎回新しく作られるので、中身で比較する
    const onlyKey = only.join(',');

    useEffect(() => {
        if (!isActive) {
            return;
        }

        const timer = window.setInterval(() => {
            // reload は状態とスクロール位置を保ったまま props だけを差し替える
            router.reload({ only: onlyKey.split(',') });
        }, POLL_INTERVAL_MS);

        return () => window.clearInterval(timer);
    }, [isActive, onlyKey]);
}
