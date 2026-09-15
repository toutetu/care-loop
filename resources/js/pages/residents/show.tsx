import { Form, Head, Link } from '@inertiajs/react';
import {
    CircleCheck,
    Info,
    MessageSquareWarning,
    Pencil,
    Printer,
    Sparkles,
    TriangleAlert,
} from 'lucide-react';
import LlmActionController from '@/actions/App/Http/Controllers/LlmActionController';
import {
    ProgressBadge,
    RecordStatusBadge,
    SeverityBadge,
    SourceBadge,
} from '@/components/care/badges';
import { EvidenceList } from '@/components/care/evidence-list';
import { LlmJobNotice } from '@/components/care/llm-job-notice';
import { EmptyState, Section } from '@/components/care/section';
import { TrendChart } from '@/components/care/trend-chart';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { useLlmJobPolling } from '@/hooks/use-llm-job';
import { NOTICE_SURFACE } from '@/lib/care-presentation';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import records from '@/routes/records';
import residentRoutes from '@/routes/residents';
import type {
    GoalProgress,
    LlmJobSummary,
    RiskAssessment,
    VerbalContact,
} from '@/types/care';

type Resident = {
    id: number;
    name: string;
    nameKana: string;
    age: number | null;
    gender: string;
    careLevel: string | null;
    medicalHistory: string | null;
    familyContact: string | null;
    careManager: string | null;
    weekdays: string[];
    nextVisit: string | null;
    startedAt: string | null;
};

type ServiceRecordRow = {
    id: number;
    date: string;
    temperature: number | null;
    waterMl: number | null;
    stapleRate: number | null;
    bathing: string | null;
    recorder: string | null;
    confirmed: boolean;
    hasAiDraft: boolean;
    excerpt: string;
    /** 記録を編集できるか。一般職員は自分が記録したものだけ。 */
    canEdit: boolean;
};

type Props = {
    resident: Resident;
    weights: { date: string; weightKg: number; lossRate: number | null }[];
    water: { target: number; series: { date: string; ml: number }[] };
    carePlan: {
        id: number;
        periodFrom: string;
        periodTo: string;
        longTermGoal: string | null;
        goals: { id: number; text: string }[];
    } | null;
    records: ServiceRecordRow[];
    riskAssessment: RiskAssessment | null;
    goalProgress: GoalProgress | null;
    verbalContacts: VerbalContact[];
    /** AI処理の最新ジョブ。実行中の表示と、完了・失敗の通知に使う。 */
    llmJobs: {
        riskDetection: LlmJobSummary | null;
        goalProgress: LlmJobSummary | null;
    };
    /** ご利用者情報を編集できるか。生活相談員以上（ResidentPolicy）。 */
    canEdit: boolean;
};

export default function ResidentShow({
    resident,
    weights,
    water,
    carePlan,
    records: serviceRecords,
    riskAssessment,
    goalProgress,
    verbalContacts,
    llmJobs,
    canEdit,
}: Props) {
    const latestLoss = weights.at(-1)?.lossRate ?? null;

    // AI処理はキューで動く。実行中のあいだだけ結果の項目を読み直し、
    // 終わったら通知を出す。押したこの画面で完結させる。
    useLlmJobPolling(
        llmJobs.riskDetection,
        ['llmJobs', 'riskAssessment', 'verbalContacts'],
        `resident-${resident.id}`,
    );
    useLlmJobPolling(
        llmJobs.goalProgress,
        ['llmJobs', 'goalProgress'],
        `resident-${resident.id}`,
    );

    const detecting = llmJobs.riskDetection?.isActive ?? false;
    const summarizing = llmJobs.goalProgress?.isActive ?? false;

    return (
        <>
            <Head title={`${resident.name} 様`} />

            <div className="flex flex-col gap-4 p-4">
                {/* --- 基本情報 --- */}
                <Card>
                    <CardContent className="py-1">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h1 className="text-lg font-semibold">
                                    {resident.name} 様
                                </h1>
                                <p className="text-muted-foreground text-sm">
                                    {resident.nameKana}
                                    {resident.age !== null &&
                                        ` ／ ${resident.age}歳`}
                                    {` ／ ${resident.gender}`}
                                </p>
                            </div>
                            <div className="flex flex-wrap items-center gap-2">
                                {canEdit && (
                                    <Button variant="outline" size="sm" asChild>
                                        <Link
                                            href={residentRoutes.edit(
                                                resident.id,
                                            )}
                                        >
                                            <Pencil
                                                className="size-4"
                                                aria-hidden
                                            />
                                            情報を編集
                                        </Link>
                                    </Button>
                                )}
                                {resident.careLevel && (
                                    <Badge variant="secondary">
                                        {resident.careLevel}
                                    </Badge>
                                )}
                                <Badge variant="outline">
                                    利用曜日{' '}
                                    {resident.weekdays.join('・') || '—'}
                                </Badge>
                                {resident.nextVisit && (
                                    <Badge variant="outline">
                                        次回 {resident.nextVisit}
                                    </Badge>
                                )}
                            </div>
                        </div>

                        <dl className="mt-4 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                            <div className="flex gap-2">
                                <dt className="text-muted-foreground w-28 shrink-0 whitespace-nowrap">
                                    既往歴
                                </dt>
                                <dd>{resident.medicalHistory ?? '—'}</dd>
                            </div>
                            <div className="flex gap-2">
                                <dt className="text-muted-foreground w-28 shrink-0 whitespace-nowrap">
                                    ご家族
                                </dt>
                                <dd>{resident.familyContact ?? '—'}</dd>
                            </div>
                            <div className="flex gap-2">
                                <dt className="text-muted-foreground w-28 shrink-0 whitespace-nowrap">
                                    介護支援専門員
                                </dt>
                                <dd>{resident.careManager ?? '—'}</dd>
                            </div>
                            <div className="flex gap-2">
                                <dt className="text-muted-foreground w-28 shrink-0 whitespace-nowrap">
                                    利用開始
                                </dt>
                                <dd>{resident.startedAt ?? '—'}</dd>
                            </div>
                        </dl>
                    </CardContent>
                </Card>

                {/* --- 口頭連絡 --- */}
                {verbalContacts.length > 0 && (
                    <Section
                        title="お迎えの際にお伝えする事項"
                        description="連絡帳に書いたうえで、口頭でもお伝えします。"
                    >
                        <ul className="space-y-2">
                            {verbalContacts.map((task) => (
                                <li
                                    key={task.id}
                                    className={cn(
                                        'rounded-md border px-3 py-2',
                                        NOTICE_SURFACE.warning,
                                    )}
                                >
                                    <p className="flex flex-wrap items-center gap-2 text-sm font-medium">
                                        <MessageSquareWarning
                                            className="size-4 shrink-0"
                                            aria-hidden
                                        />
                                        {task.topic}
                                        <Badge variant="outline">
                                            {task.urgencyLabel}
                                        </Badge>
                                    </p>
                                    {task.reason && (
                                        <p className="text-muted-foreground mt-1 text-sm">
                                            {task.reason}
                                        </p>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </Section>
                )}

                {/* --- リスク兆候 --- */}
                <Section
                    title="リスク兆候"
                    description={
                        riskAssessment
                            ? `${riskAssessment.periodFrom} 〜 ${riskAssessment.periodTo} の記録から抽出（${riskAssessment.assessedAt} 実行）`
                            : 'まだ抽出していません。'
                    }
                    action={
                        <Form
                            {...LlmActionController.detectRisks.form(
                                resident.id,
                            )}
                            options={{ preserveScroll: true }}
                        >
                            {({ processing }) => (
                                // 受け付けたあともワーカーが動いているあいだは押させない。
                                // 押した回数だけ積まれ、その数だけ費用がかかる。
                                <Button
                                    type="submit"
                                    size="sm"
                                    pending={processing || detecting}
                                >
                                    {!processing && !detecting && (
                                        <Sparkles
                                            className="size-4"
                                            aria-hidden
                                        />
                                    )}
                                    {processing || detecting
                                        ? '抽出しています…'
                                        : 'リスク兆候を抽出'}
                                </Button>
                            )}
                        </Form>
                    }
                >
                    <LlmJobNotice
                        job={llmJobs.riskDetection}
                        className="mb-3"
                    />
                    {riskAssessment === null ? (
                        <EmptyState>
                            上のボタンから抽出できます。数値の判定はルールベースで算出し、
                            記述からしか分からない変化のみAIが抽出します。
                        </EmptyState>
                    ) : riskAssessment.findings.length === 0 ? (
                        <p className="flex items-center gap-2 py-4 text-sm">
                            <CircleCheck
                                className="text-success-ink size-4"
                                aria-hidden
                            />
                            この期間に該当する兆候はありませんでした。
                        </p>
                    ) : (
                        <div className="space-y-4">
                            {!riskAssessment.isReviewed && (
                                <p
                                    className={cn(
                                        'flex items-start gap-2 rounded-md border px-3 py-2 text-sm',
                                        NOTICE_SURFACE.warning,
                                    )}
                                >
                                    <TriangleAlert
                                        className="mt-0.5 size-4 shrink-0"
                                        aria-hidden
                                    />
                                    まだ職員が確認していない抽出結果です。根拠の記録をご確認ください。
                                </p>
                            )}

                            {riskAssessment.findings.map((finding) => (
                                <article
                                    key={finding.id}
                                    className="rounded-lg border p-3 sm:p-4"
                                >
                                    <div className="flex flex-wrap items-center gap-2">
                                        <SeverityBadge
                                            severity={finding.severity}
                                            label={finding.severityLabel}
                                        />
                                        <SourceBadge source={finding.source} />
                                        <Badge variant="outline">
                                            {finding.category}
                                        </Badge>
                                    </div>

                                    <h3 className="mt-2 font-medium">
                                        {finding.title}
                                    </h3>
                                    <p className="text-muted-foreground mt-1 text-sm">
                                        {finding.reason}
                                    </p>

                                    <div className="mt-3">
                                        <p className="text-muted-foreground mb-1 text-xs font-medium">
                                            根拠となる記録
                                        </p>
                                        <EvidenceList
                                            evidence={finding.evidence}
                                        />
                                    </div>

                                    {finding.suggestedActions.length > 0 && (
                                        <div className="mt-3">
                                            <p className="text-muted-foreground mb-1 text-xs font-medium">
                                                対応の候補（職員がご判断ください）
                                            </p>
                                            <ul className="list-disc space-y-0.5 pl-5 text-sm">
                                                {finding.suggestedActions.map(
                                                    (action) => (
                                                        <li key={action}>
                                                            {action}
                                                        </li>
                                                    ),
                                                )}
                                            </ul>
                                        </div>
                                    )}
                                </article>
                            ))}
                        </div>
                    )}
                </Section>

                {/* --- 目標進捗 --- */}
                <Section
                    title="目標進捗要約"
                    description={
                        goalProgress
                            ? `${goalProgress.periodFrom} 〜 ${goalProgress.periodTo} の記録から作成`
                            : 'まだ作成していません。'
                    }
                    action={
                        <Form
                            {...LlmActionController.goalProgress.form(
                                resident.id,
                            )}
                            options={{ preserveScroll: true }}
                        >
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    size="sm"
                                    pending={processing || summarizing}
                                    disabled={
                                        processing ||
                                        summarizing ||
                                        carePlan === null
                                    }
                                    title={
                                        carePlan === null
                                            ? '有効な通所介護計画書がありません'
                                            : undefined
                                    }
                                >
                                    {!processing && !summarizing && (
                                        <Sparkles
                                            className="size-4"
                                            aria-hidden
                                        />
                                    )}
                                    {processing || summarizing
                                        ? '要約しています…'
                                        : '進捗を要約'}
                                </Button>
                            )}
                        </Form>
                    }
                >
                    <LlmJobNotice job={llmJobs.goalProgress} className="mb-3" />
                    {goalProgress === null ? (
                        <EmptyState>
                            通所介護計画書の短期目標ごとに、記録から進捗を評価します。
                        </EmptyState>
                    ) : (
                        <div className="space-y-4">
                            {/* 確信度が低い要約をそのまま転記させないための歯止め */}
                            {goalProgress.isLowConfidence && (
                                <p
                                    className={cn(
                                        'flex items-start gap-2 rounded-md border px-3 py-2 text-sm',
                                        NOTICE_SURFACE.warning,
                                    )}
                                >
                                    <TriangleAlert
                                        className="mt-0.5 size-4 shrink-0"
                                        aria-hidden
                                    />
                                    確信度が低い要約です。モニタリング記録への転記は控えてください。
                                </p>
                            )}

                            <p className="bg-muted text-muted-foreground flex items-start gap-2 rounded-md px-3 py-2 text-xs">
                                <Info
                                    className="mt-0.5 size-3.5 shrink-0"
                                    aria-hidden
                                />
                                これは下書きです。内容をご確認のうえ、職員の判断でモニタリング記録へ転記してください。
                            </p>

                            {goalProgress.overallSummary && (
                                <p className="text-sm leading-relaxed">
                                    {goalProgress.overallSummary}
                                </p>
                            )}

                            <ul className="space-y-3">
                                {goalProgress.items.map((item) => (
                                    <li
                                        key={item.id}
                                        className="rounded-lg border p-3"
                                    >
                                        <div className="flex flex-wrap items-center gap-2">
                                            <ProgressBadge
                                                status={item.status}
                                                label={item.statusLabel}
                                            />
                                            <span className="text-sm font-medium">
                                                {item.goalText ??
                                                    '（目標が削除されています）'}
                                            </span>
                                        </div>
                                        {item.comment && (
                                            <p className="text-muted-foreground mt-1 text-sm">
                                                {item.comment}
                                            </p>
                                        )}
                                        <div className="mt-2">
                                            <EvidenceList
                                                evidence={item.evidence}
                                            />
                                        </div>
                                    </li>
                                ))}
                            </ul>

                            {goalProgress.nextActions.length > 0 && (
                                <div>
                                    <p className="text-muted-foreground mb-1 text-xs font-medium">
                                        次の期間に向けて
                                    </p>
                                    <ul className="list-disc space-y-0.5 pl-5 text-sm">
                                        {goalProgress.nextActions.map(
                                            (action) => (
                                                <li key={action}>{action}</li>
                                            ),
                                        )}
                                    </ul>
                                </div>
                            )}
                        </div>
                    )}
                </Section>

                {/* --- 推移 --- */}
                <div className="grid gap-4 lg:grid-cols-2">
                    <Section
                        title="体重の推移"
                        description={
                            latestLoss !== null && latestLoss >= 0.03
                                ? `直近で ${(latestLoss * 100).toFixed(1)}% 減少しています`
                                : '月1回の測定値です'
                        }
                    >
                        <TrendChart
                            points={weights.map((row) => ({
                                label: row.date,
                                value: row.weightKg,
                            }))}
                            unit="kg"
                            formatValue={(value) => `${value.toFixed(1)}`}
                        />
                    </Section>

                    <Section
                        title="1日の水分摂取量"
                        description={`目標 ${water.target.toLocaleString()} ml`}
                    >
                        <TrendChart
                            points={water.series.map((row) => ({
                                label: row.date,
                                value: row.ml,
                            }))}
                            target={water.target}
                            unit="ml"
                            formatValue={(value) => value.toLocaleString()}
                        />
                    </Section>
                </div>

                {/* --- 通所介護計画書 --- */}
                {carePlan && (
                    <Section
                        title="通所介護計画書"
                        description={`${carePlan.periodFrom} 〜 ${carePlan.periodTo}`}
                    >
                        {carePlan.longTermGoal && (
                            <div className="mb-3">
                                <p className="text-muted-foreground text-xs font-medium">
                                    長期目標
                                </p>
                                <p className="text-sm">
                                    {carePlan.longTermGoal}
                                </p>
                            </div>
                        )}
                        <p className="text-muted-foreground text-xs font-medium">
                            短期目標
                        </p>
                        <ol className="mt-1 list-decimal space-y-1 pl-5 text-sm">
                            {carePlan.goals.map((goal) => (
                                <li key={goal.id}>{goal.text}</li>
                            ))}
                        </ol>
                    </Section>
                )}

                {/* --- 直近の記録 --- */}
                <Section
                    title="直近のサービス提供記録"
                    description="日付を選ぶと記録の入力画面へ移動します。"
                >
                    {serviceRecords.length === 0 ? (
                        <EmptyState>記録がありません。</EmptyState>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[700px] text-sm">
                                <thead>
                                    <tr className="text-muted-foreground border-b text-left text-xs">
                                        <th className="pb-2 font-medium">
                                            日付
                                        </th>
                                        <th className="pb-2 font-medium">
                                            体温
                                        </th>
                                        <th className="pb-2 font-medium">
                                            水分
                                        </th>
                                        <th className="pb-2 font-medium">
                                            主食
                                        </th>
                                        <th className="pb-2 font-medium">
                                            入浴・清拭
                                        </th>
                                        <th className="pb-2 font-medium">
                                            記録
                                        </th>
                                        <th className="pb-2" />
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {serviceRecords.map((row) => (
                                        <tr key={row.id}>
                                            <td className="py-2 whitespace-nowrap">
                                                <Link
                                                    href={records.edit(row.id)}
                                                    className="font-medium hover:underline"
                                                    title={
                                                        row.canEdit
                                                            ? undefined
                                                            : `${row.recorder} が記録（閲覧のみ）`
                                                    }
                                                >
                                                    {row.date}
                                                </Link>
                                            </td>
                                            <td className="py-2 tabular-nums">
                                                {row.temperature !== null
                                                    ? `${row.temperature.toFixed(1)} ℃`
                                                    : '未測定'}
                                            </td>
                                            <td className="py-2 tabular-nums">
                                                {row.waterMl !== null
                                                    ? `${row.waterMl.toLocaleString()} ml`
                                                    : '—'}
                                            </td>
                                            <td className="py-2 tabular-nums">
                                                {row.stapleRate !== null
                                                    ? `${row.stapleRate}%`
                                                    : '—'}
                                            </td>
                                            <td className="py-2">
                                                {row.bathing ?? '—'}
                                            </td>
                                            <td className="py-2">
                                                <RecordStatusBadge
                                                    status={
                                                        row.hasAiDraft
                                                            ? 'ai_draft'
                                                            : row.confirmed
                                                              ? 'confirmed'
                                                              : 'draft'
                                                    }
                                                />
                                            </td>
                                            <td className="py-2 text-right">
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    asChild
                                                >
                                                    <a
                                                        href={
                                                            records.familyReport(
                                                                row.id,
                                                            ).url
                                                        }
                                                        target="_blank"
                                                        rel="noreferrer"
                                                        title="連絡帳を印刷"
                                                    >
                                                        <Printer
                                                            className="size-4"
                                                            aria-hidden
                                                        />
                                                    </a>
                                                </Button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Section>
            </div>
        </>
    );
}

ResidentShow.layout = {
    breadcrumbs: [
        { title: 'ダッシュボード', href: dashboard() },
        { title: '利用者一覧', href: residentRoutes.index() },
    ],
};
