import { Head, Link, router } from '@inertiajs/react';
import { ChevronRight, RefreshCw, TriangleAlert, X } from 'lucide-react';
import { EmptyState, Section, StatCard } from '@/components/care/section';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import llmJobs from '@/routes/llm-jobs';
import llmLogs from '@/routes/llm-logs';
import records from '@/routes/records';
import residents from '@/routes/residents';

type Job = {
    id: number;
    feature: string;
    featureCode: string;
    status: string;
    statusLabel: string;
    requester: string | null;
    attempts: number;
    durationSeconds: number | null;
    startedAt: string | null;
    errorLabel: string | null;
    errorMessage: string | null;
    isRetryable: boolean;
    needsOperatorAttention: boolean;
    targetLabel: string | null;
    targetRecordId: number | null;
    targetResidentId: number | null;
};

type Props = {
    counts: {
        total: number;
        succeeded: number;
        failed: number;
        running: number;
    };
    onlyFailed: boolean;
    canViewLlmLogs: boolean;
    jobs: {
        data: Job[];
        currentPage: number;
        lastPage: number;
        total: number;
    };
};

/**
 * AI処理の実行状況。
 *
 * 【AI利用ログとの違い】
 * ここは「自分が動かしたAIがどうなったか」を見る画面で、職員全員が開ける。
 * AI利用ログは費用とトークン数を扱う運営の画面で、管理者に限っている。
 *
 * 職員にとって必要なのは、押した処理が通ったのか失敗したのか、
 * 失敗したなら次に何をすればよいのかである。
 */
export default function LlmJobIndex({
    counts,
    onlyFailed,
    canViewLlmLogs,
    jobs,
}: Props) {
    const filter = (failedOnly: boolean) => {
        router.get(
            llmJobs.index().url,
            failedOnly ? { status: 'failed' } : {},
            { preserveScroll: true },
        );
    };

    return (
        <>
            <Head title="AI処理の実行状況" />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h1 className="text-lg font-semibold">AI処理の実行状況</h1>
                    {canViewLlmLogs && (
                        <Button variant="outline" size="sm" asChild>
                            <Link href={llmLogs.index()}>
                                AI利用ログ（費用）
                                <ChevronRight className="size-4" aria-hidden />
                            </Link>
                        </Button>
                    )}
                </div>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard
                        label="実行回数"
                        value={counts.total}
                        unit="件"
                        hint={onlyFailed ? '押すとすべて表示します' : undefined}
                        onClick={onlyFailed ? () => filter(false) : undefined}
                    />
                    <StatCard label="成功" value={counts.succeeded} unit="件" />
                    <StatCard
                        label="失敗"
                        value={counts.failed}
                        unit="件"
                        tone={counts.failed > 0 ? 'alert' : 'default'}
                        hint={
                            counts.failed > 0
                                ? '押すと失敗のみ表示します'
                                : undefined
                        }
                        onClick={
                            counts.failed > 0 ? () => filter(true) : undefined
                        }
                    />
                    <StatCard label="実行中" value={counts.running} unit="件" />
                </div>

                <Section
                    title={onlyFailed ? '失敗した実行' : '実行の履歴'}
                    description={`全 ${jobs.total.toLocaleString()} 件中 ${jobs.currentPage} / ${jobs.lastPage} ページ`}
                    action={
                        onlyFailed && (
                            <Button
                                variant="secondary"
                                size="sm"
                                onClick={() => filter(false)}
                            >
                                <X className="size-4" aria-hidden />
                                絞り込みを解除
                            </Button>
                        )
                    }
                >
                    {jobs.data.length === 0 ? (
                        <EmptyState>
                            {onlyFailed
                                ? '失敗した実行はありません。'
                                : 'まだ実行されていません。'}
                        </EmptyState>
                    ) : (
                        <ul className="divide-y">
                            {jobs.data.map((job) => (
                                <li
                                    key={job.id}
                                    className="py-3 first:pt-0 last:pb-0"
                                >
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Badge
                                            variant={
                                                job.status === 'failed'
                                                    ? 'destructive'
                                                    : 'secondary'
                                            }
                                        >
                                            {job.statusLabel}
                                        </Badge>
                                        <span className="text-muted-foreground font-mono text-xs">
                                            {job.featureCode}
                                        </span>
                                        <span className="font-medium">
                                            {job.feature}
                                        </span>
                                        {job.errorLabel && (
                                            <Badge variant="outline">
                                                {job.errorLabel}
                                            </Badge>
                                        )}
                                        {/* 自動で直るものと、担当者の対応が要るものを分ける。
                                            「混み合っています」と「設定が違います」では
                                            取るべき行動が違う。 */}
                                        {job.isRetryable && (
                                            <Badge
                                                variant="secondary"
                                                className="gap-1"
                                            >
                                                <RefreshCw
                                                    className="size-3"
                                                    aria-hidden
                                                />
                                                自動で再試行
                                            </Badge>
                                        )}
                                        {job.needsOperatorAttention && (
                                            <Badge
                                                variant="destructive"
                                                className="gap-1"
                                            >
                                                <TriangleAlert
                                                    className="size-3"
                                                    aria-hidden
                                                />
                                                要対応
                                            </Badge>
                                        )}
                                    </div>

                                    {job.errorMessage && (
                                        <p className="text-muted-foreground mt-1 text-sm">
                                            {job.errorMessage}
                                        </p>
                                    )}

                                    <p className="text-muted-foreground mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                                        {/* 対象が分からないと、どの記録の話なのか辿れない */}
                                        {job.targetLabel && (
                                            <TargetLink job={job} />
                                        )}
                                        <span>{job.startedAt ?? '—'}</span>
                                        <span>{job.requester ?? '—'}</span>
                                        {job.durationSeconds !== null && (
                                            <span className="tabular-nums">
                                                {job.durationSeconds.toFixed(1)}{' '}
                                                秒
                                            </span>
                                        )}
                                        {job.attempts > 1 && (
                                            <span>試行 {job.attempts} 回</span>
                                        )}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    )}
                </Section>
            </div>
        </>
    );
}

/** 対象の記録またはご利用者へ移動する。削除済みならリンクにしない。 */
function TargetLink({ job }: { job: Job }) {
    if (job.targetRecordId !== null) {
        return (
            <Link
                href={records.edit(job.targetRecordId)}
                className="text-foreground font-medium hover:underline"
            >
                {job.targetLabel}
            </Link>
        );
    }

    if (job.targetResidentId !== null) {
        return (
            <Link
                href={residents.show(job.targetResidentId)}
                className="text-foreground font-medium hover:underline"
            >
                {job.targetLabel}
            </Link>
        );
    }

    return <span>{job.targetLabel}</span>;
}

LlmJobIndex.layout = {
    breadcrumbs: [
        { title: 'ダッシュボード', href: dashboard() },
        { title: 'AI処理の実行状況', href: llmJobs.index() },
    ],
};
