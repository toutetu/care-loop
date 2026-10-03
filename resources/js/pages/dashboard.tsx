import { Head } from '@inertiajs/react';
import { CalendarDays } from 'lucide-react';
import type { AnnouncementSummary } from '@/components/care/announcement-card';
import {
    AnnouncementSection,
    DirectMessageSection,
    UrgentRiskSection,
    VerbalContactSection,
} from '@/components/care/notice-lists';
import type { DirectMessage, UrgentRisk } from '@/components/care/notice-lists';
import { PageHeader } from '@/components/care/page-header';
import { StatCard } from '@/components/care/section';
import { Badge } from '@/components/ui/badge';
import { dashboard } from '@/routes';
import llmJobs from '@/routes/llm-jobs';
import records from '@/routes/records';
import type { VerbalContact } from '@/types/care';

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
    canViewLlmJobs: boolean;
    /** 本人がまだ確認していない周知と、未読の個別の連絡。無ければ欄ごと出さない。 */
    announcements: AnnouncementSummary[];
    directMessages: DirectMessage[];
};

/**
 * ダッシュボード。朝礼と申し送りの場面で開く画面。
 *
 * 【一覧を置かない】
 * ここは全体を見る場所で、記録を埋めるのは記録一覧、AIの結果を追うのは
 * AI処理の実行状況が担う。数字を出し、押せばその画面へ移れるようにする。
 * 目的の違う一覧を1画面に並べると、どれも中途半端になる。
 *
 * 【本人宛てのものだけは先頭に出す】
 * PC には下のバーがなく、お知らせの件数が目に入らない。管理者からの周知と
 * 個別の連絡は本人にしか届かないので、確認を済ませるまでここの先頭に出す。
 */
export default function Dashboard({
    day,
    counts,
    risks,
    verbalContacts,
    llm,
    canViewLlmJobs,
    announcements,
    directMessages,
}: Props) {
    return (
        <>
            <Head title="ダッシュボード" />

            <div className="flex flex-col gap-4 p-4">
                {/* 休業日に開くと一覧が空になるため、いつの分を見ているのかを必ず書く */}
                <PageHeader
                    icon={CalendarDays}
                    title={day.label}
                    meta={
                        !day.isToday && (
                            <Badge variant="secondary">
                                本日はご利用がないため、直近の利用日を表示しています
                            </Badge>
                        )
                    }
                />

                <DirectMessageSection directMessages={directMessages} />
                <AnnouncementSection announcements={announcements} />

                {/* 数字を見た職員が次にすることは「その中身を見る」である。
                    カードから、それぞれの一覧へ直接移れるようにする。 */}
                <div className="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
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
                        tone={counts.unconfirmed > 0 ? 'warning' : 'default'}
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
                        tone={risks.length > 0 ? 'danger' : 'default'}
                        hint="重要度 高・未確認のもの"
                    />
                    {/* 費用は全員に見せる。1回あたりの単価が小さいと
                        呼び放題という誤解が生まれるため（DashboardController）。
                        ただし明細のAI実行状況は生活相談員以上なので、
                        介護職員には押せないカードとして出す。 */}
                    <StatCard
                        label="今月のAI利用料"
                        value={`${llm.spentUsd.toFixed(2)}`}
                        hint={`成功 ${llm.succeeded} 件 ／ 失敗 ${llm.failed} 件`}
                        tone={llm.failed > 0 ? 'warning' : 'default'}
                        href={canViewLlmJobs ? llmJobs.index() : undefined}
                    />
                </div>

                <UrgentRiskSection risks={risks} />
                <VerbalContactSection contacts={verbalContacts} />
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [{ title: 'ダッシュボード', href: dashboard() }],
};
