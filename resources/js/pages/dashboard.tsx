import { Head, Link } from '@inertiajs/react';
import { CalendarDays, ChevronRight, MessageSquareWarning, Printer } from 'lucide-react';
import {
    RecordStatusBadge,
    SeverityBadge,
    SourceBadge,
} from '@/components/care/badges';
import { EmptyState, Section, StatCard } from '@/components/care/section';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import llmLogs from '@/routes/llm-logs';
import records from '@/routes/records';
import residents from '@/routes/residents';
import type { RecordStatus, RiskSeverity, RiskSource, VerbalContact } from '@/types/care';

type Attendance = {
    recordId: number;
    residentId: number;
    name: string;
    careLevel: string | null;
    arrivalTime: string | null;
    departureTime: string | null;
    temperature: number | null;
    recorder: string | null;
    status: RecordStatus;
    bathing: boolean | null;
    /** 記録を編集できるか。一般職員は自分が記録したものだけ。 */
    canEdit: boolean;
};

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

type RecentJob = {
    id: number;
    feature: string;
    status: string;
    statusLabel: string;
    requester: string | null;
    errorLabel: string | null;
    finishedAt: string | null;
};

type Props = {
    day: { date: string; label: string; isToday: boolean };
    attendance: Attendance[];
    risks: UrgentRisk[];
    verbalContacts: VerbalContact[];
    llm: {
        succeeded: number;
        failed: number;
        spentUsd: number;
        budgetUsd: number;
        usageRate: number;
        cacheHitRate: number;
        recent: RecentJob[];
    };
    canViewLlmLogs: boolean;
};

export default function Dashboard({
    day,
    attendance,
    risks,
    verbalContacts,
    llm,
    canViewLlmLogs,
}: Props) {
    const unconfirmed = attendance.filter((row) => row.status !== 'confirmed').length;

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

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard label="ご利用者" value={attendance.length} unit="名" />
                    <StatCard
                        label="未確定の記録"
                        value={unconfirmed}
                        unit="件"
                        tone={unconfirmed > 0 ? 'alert' : 'default'}
                        hint={unconfirmed > 0 ? '本日中にご確認ください' : 'すべて確定済みです'}
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
                        hint={`上限 $${llm.budgetUsd.toFixed(2)} の ${Math.round(
                            llm.usageRate * 100,
                        )}%`}
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

                <Section
                    title="ご利用者と記録の状況"
                    description={`${day.label}のご利用状況です。`}
                    action={
                        <Button variant="outline" size="sm" asChild>
                            <Link href={residents.index()}>
                                利用者一覧
                                <ChevronRight className="size-4" aria-hidden />
                            </Link>
                        </Button>
                    }
                >
                    {attendance.length === 0 ? (
                        <EmptyState>この日のご利用記録はありません。</EmptyState>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[680px] text-sm">
                                <thead>
                                    <tr className="border-b text-left text-xs text-muted-foreground">
                                        <th className="pb-2 font-medium">お名前</th>
                                        <th className="pb-2 font-medium">要介護度</th>
                                        <th className="pb-2 font-medium">到着／帰宅</th>
                                        <th className="pb-2 font-medium">体温</th>
                                        <th className="pb-2 font-medium">入浴</th>
                                        <th className="pb-2 font-medium">記録</th>
                                        <th className="pb-2" />
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {attendance.map((row) => (
                                        <tr key={row.recordId}>
                                            <td className="py-2">
                                                <Link
                                                    href={residents.show(row.residentId)}
                                                    className="font-medium hover:underline"
                                                >
                                                    {row.name}
                                                </Link>
                                            </td>
                                            <td className="py-2 text-muted-foreground">
                                                {row.careLevel ?? '—'}
                                            </td>
                                            <td className="py-2 tabular-nums text-muted-foreground">
                                                {row.arrivalTime ?? '—'} 〜 {row.departureTime ?? '—'}
                                            </td>
                                            {/* 未測定は空欄にする。0と書くと測って0だったと読めてしまう */}
                                            <td className="py-2 tabular-nums">
                                                {row.temperature !== null
                                                    ? `${row.temperature.toFixed(1)} ℃`
                                                    : '未測定'}
                                            </td>
                                            <td className="py-2 text-muted-foreground">
                                                {row.bathing === null
                                                    ? '—'
                                                    : row.bathing
                                                      ? 'あり'
                                                      : 'なし'}
                                            </td>
                                            <td className="py-2">
                                                <RecordStatusBadge status={row.status} />
                                            </td>
                                            <td className="py-2 text-right">
                                                <div className="flex justify-end gap-1">
                                                    {/* 他の職員の記録も開ける。ただし書き換えはできないので、
                                                        開く前に分かるようラベルを変える。 */}
                                                    <Button variant="ghost" size="sm" asChild>
                                                        <Link href={records.edit(row.recordId)}>
                                                            {row.canEdit ? '入力' : '表示'}
                                                        </Link>
                                                    </Button>
                                                    <Button variant="ghost" size="sm" asChild>
                                                        <a
                                                            href={records.familyReport(row.recordId).url}
                                                            target="_blank"
                                                            rel="noreferrer"
                                                            title="連絡帳を印刷"
                                                        >
                                                            <Printer className="size-4" aria-hidden />
                                                        </a>
                                                    </Button>
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Section>

                <Section
                    title="AI処理の実行状況"
                    description="今月の実行結果です。失敗も隠さずに表示します。"
                    action={
                        canViewLlmLogs && (
                            <Button variant="outline" size="sm" asChild>
                                <Link href={llmLogs.index()}>
                                    AI利用ログ
                                    <ChevronRight className="size-4" aria-hidden />
                                </Link>
                            </Button>
                        )
                    }
                >
                    <div className="grid gap-4 sm:grid-cols-3">
                        <div>
                            <p className="text-sm text-muted-foreground">成功</p>
                            <p className="text-xl font-semibold tabular-nums">{llm.succeeded} 件</p>
                        </div>
                        <div>
                            <p className="text-sm text-muted-foreground">失敗</p>
                            <p className="text-xl font-semibold tabular-nums">{llm.failed} 件</p>
                        </div>
                        <div>
                            <p className="text-sm text-muted-foreground">キャッシュ利用率</p>
                            <p className="text-xl font-semibold tabular-nums">
                                {Math.round(llm.cacheHitRate * 100)} %
                            </p>
                        </div>
                    </div>

                    {llm.recent.length > 0 && (
                        <ul className="mt-4 divide-y border-t text-sm">
                            {llm.recent.map((job) => (
                                <li key={job.id} className="flex flex-wrap items-center gap-2 py-2">
                                    <Badge
                                        variant={job.status === 'failed' ? 'destructive' : 'secondary'}
                                    >
                                        {job.statusLabel}
                                    </Badge>
                                    <span>{job.feature}</span>
                                    {job.errorLabel && (
                                        <span className="text-muted-foreground">（{job.errorLabel}）</span>
                                    )}
                                    <span className="ml-auto text-xs text-muted-foreground">
                                        {job.requester} ／ {job.finishedAt ?? '—'}
                                    </span>
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
