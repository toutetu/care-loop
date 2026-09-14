import { Head, Link } from '@inertiajs/react';
import { CalendarDays, MessageSquareWarning } from 'lucide-react';
import { SeverityBadge, SourceBadge } from '@/components/care/badges';
import { EmptyState, Section, StatCard } from '@/components/care/section';
import { Badge } from '@/components/ui/badge';
import { dashboard } from '@/routes';
import llmJobs from '@/routes/llm-jobs';
import records from '@/routes/records';
import residents from '@/routes/residents';
import type { RiskSeverity, RiskSource, VerbalContact } from '@/types/care';

type UrgentRisk = {
    id: number;
    residentId: number;
    residentName: string;
    title: string;
    category: string;
    severityLabel: string;
    source: RiskSource;
    sourceLabel: string;
    isDeterministic: boolean;
    evidenceCount: number;
    assessedOn: string;
};

type Props = {
    day: { date: string; label: string; isToday: boolean };
    counts: { residents: number; unconfirmed: number };
    risks: UrgentRisk[];
    verbalContacts: VerbalContact[];
    llm: {
        succeeded: number;
        failed: number;
        spentUsd: number;
        budgetUsd: number;
        usageRate: number;
    };
};

/**
 * ダッシュボード。朝礼と申し送りの場面で開く画面。
 *
 * 【一覧を置かない】
 * ここは全体を見る場所で、記録を埋めるのは記録一覧、AIの結果を追うのは
 * AI処理の実行状況が担う。数字を出し、押せばその画面へ移れるようにする。
 * 目的の違う一覧を1画面に並べると、どれも中途半端になる。
 */
export default function Dashboard({
    day,
    counts,
    risks,
    verbalContacts,
    llm,
}: Props) {
    return (
        <>
            <Head title="ダッシュボード" />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center gap-2">
                    <CalendarDays className="size-4 text-muted-foreground" aria-hidden />
                    <h1 className="text-lg font-semibold">{day.label}</h1>
                    {/* 休業日に開くと一覧が空になるため、いつの分を見ているのかを必ず書く */}
                    {!day.isToday && (
                        <Badge variant="secondary">
                            本日はご利用がないため、直近の利用日を表示しています
                        </Badge>
                    )}
                </div>

                {/* 数字を見た職員が次にすることは「その中身を見る」である。
                    カードから、それぞれの一覧へ直接移れるようにする。 */}
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard
                        label="ご利用者"
                        value={counts.residents}
                        unit="名"
                        hint="押すと記録一覧へ"
                        href={records.index({ query: { date: day.date } })}
                    />
                    <StatCard
                        label="未確定の記録"
                        value={counts.unconfirmed}
                        unit="件"
                        tone={counts.unconfirmed > 0 ? 'alert' : 'default'}
                        hint={
                            counts.unconfirmed > 0
                                ? '押すと未確定のみ表示します'
                                : 'すべて確定済みです'
                        }
                        href={records.index({
                            query: { date: day.date, status: 'unconfirmed' },
                        })}
                    />
                    <StatCard
                        label="要対応のリスク"
                        value={risks.length}
                        unit="件"
                        tone={risks.length > 0 ? 'alert' : 'default'}
                        hint="重要度 高・未確認のもの"
                    />
                    <StatCard
                        label="今月のAI利用料"
                        value={`$${llm.spentUsd.toFixed(2)}`}
                        hint={`成功 ${llm.succeeded} 件 ／ 失敗 ${llm.failed} 件`}
                        tone={llm.failed > 0 ? 'alert' : 'default'}
                        href={llmJobs.index()}
                    />
                </div>

                <Section
                    title="要対応のリスク"
                    description="重要度が高く、まだ職員が確認していない指摘です。根拠の記録を確認してからご判断ください。"
                >
                    {risks.length === 0 ? (
                        <EmptyState>未確認の重要な指摘はありません。</EmptyState>
                    ) : (
                        <ul className="divide-y">
                            {risks.map((risk) => (
                                <li key={risk.id} className="py-3 first:pt-0 last:pb-0">
                                    <Link
                                        href={residents.show(risk.residentId)}
                                        className="-mx-2 block rounded px-2 py-1 hover:bg-accent"
                                    >
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="font-medium">{risk.residentName} 様</span>
                                            <SeverityBadge severity={'high' as RiskSeverity} label={risk.severityLabel} />
                                            <SourceBadge source={risk.source} label={risk.sourceLabel} />
                                            <Badge variant="outline">{risk.category}</Badge>
                                        </div>
                                        <p className="mt-1 text-sm">{risk.title}</p>
                                        <p className="mt-0.5 text-xs text-muted-foreground">
                                            {risk.assessedOn} 抽出 ／ 根拠 {risk.evidenceCount} 件
                                            {risk.isDeterministic
                                                ? ' ／ 数値から算出'
                                                : ' ／ 記述から検出（要確認）'}
                                        </p>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </Section>

                <Section
                    title="お迎えの際にお伝えする事項"
                    description="連絡帳に書いたうえで、口頭でもお伝えする内容です。お伝えしたら完了にしてください。"
                >
                    {verbalContacts.length === 0 ? (
                        <EmptyState>お伝えする事項はありません。</EmptyState>
                    ) : (
                        <ul className="space-y-2">
                            {verbalContacts.map((task) => (
                                <li
                                    key={task.id}
                                    className="flex flex-wrap items-center gap-2 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm dark:border-amber-900 dark:bg-amber-950"
                                >
                                    <MessageSquareWarning
                                        className="size-4 shrink-0 text-amber-700 dark:text-amber-400"
                                        aria-hidden
                                    />
                                    <span className="font-medium">{task.residentName} 様</span>
                                    <span>{task.topic}</span>
                                    <Badge variant="outline">{task.urgencyLabel}</Badge>
                                </li>
                            ))}
                        </ul>
                    )}
                </Section>

            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [{ title: 'ダッシュボード', href: dashboard() }],
};
