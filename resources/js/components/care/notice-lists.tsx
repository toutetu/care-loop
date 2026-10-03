import { Link } from '@inertiajs/react';
import {
    ChevronRight,
    MessageSquareWarning,
    MessagesSquare,
} from 'lucide-react';
import {
    AnnouncementCard,
    ConfirmAnnouncementButton,
} from '@/components/care/announcement-card';
import type { AnnouncementSummary } from '@/components/care/announcement-card';
import { SeverityBadge, SourceBadge } from '@/components/care/badges';
import { EmptyState, Section } from '@/components/care/section';
import { Badge } from '@/components/ui/badge';
import { COUNT_BADGE, NOTICE_SURFACE } from '@/lib/care-presentation';
import { cn } from '@/lib/utils';
import announcementRoutes from '@/routes/announcements';
import messages from '@/routes/messages';
import residents from '@/routes/residents';
import type { RiskSeverity, RiskSource, VerbalContact } from '@/types/care';

/**
 * 「要対応のリスク」と「お迎えの際にお伝えする事項」。
 *
 * PCではダッシュボードに、スマートフォンではお知らせの画面に出す。
 * 抽出条件をサーバー側で1か所にまとめた（NoticeBoard）のと同じ理由で、
 * 見た目もここ1か所で決める。片方だけ直すと、端末によって読める内容が変わる。
 */

export type UrgentRisk = {
    id: number;
    residentId: number;
    residentName: string;
    title: string;
    category: string;
    severityLabel: string;
    source: RiskSource;
    isDeterministic: boolean;
    evidenceCount: number;
    assessedOn: string;
};

export function UrgentRiskSection({ risks }: { risks: UrgentRisk[] }) {
    return (
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
                                className="hover:bg-accent -mx-2 block rounded px-2 py-1"
                            >
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="font-medium">
                                        {risk.residentName} 様
                                    </span>
                                    <SeverityBadge
                                        severity={'high' as RiskSeverity}
                                        label={risk.severityLabel}
                                    />
                                    <SourceBadge source={risk.source} />
                                    <Badge variant="outline">
                                        {risk.category}
                                    </Badge>
                                </div>
                                <p className="mt-1 text-sm">{risk.title}</p>
                                <p className="text-muted-foreground mt-0.5 text-xs">
                                    {risk.assessedOn} 抽出 ／ 根拠{' '}
                                    {risk.evidenceCount} 件
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
    );
}

export function VerbalContactSection({
    contacts,
}: {
    contacts: VerbalContact[];
}) {
    return (
        <Section
            title="お迎えの際にお伝えする事項"
            description="連絡帳に書いたうえで、口頭でもお伝えする内容です。お伝えしたら完了にしてください。"
        >
            {contacts.length === 0 ? (
                <EmptyState>お伝えする事項はありません。</EmptyState>
            ) : (
                <ul className="space-y-2">
                    {contacts.map((task) => (
                        <li
                            key={task.id}
                            className={cn(
                                'flex flex-wrap items-center gap-2 rounded-md border px-3 py-2 text-sm',
                                NOTICE_SURFACE.warning,
                            )}
                        >
                            <MessageSquareWarning
                                className="size-4 shrink-0"
                                aria-hidden
                            />
                            <span className="font-medium">
                                {task.residentName} 様
                            </span>
                            <span>{task.topic}</span>
                            <Badge variant="outline">{task.urgencyLabel}</Badge>
                        </li>
                    ))}
                </ul>
            )}
        </Section>
    );
}

export type DirectMessage = { roomId: number; from: string; unread: number };

/**
 * あなた宛ての個別の連絡（未読のもの）。
 *
 * 無いときは何も出さない。空の枠があると、毎回読み飛ばす場所が1つ増える。
 */
export function DirectMessageSection({
    directMessages,
}: {
    directMessages: DirectMessage[];
}) {
    if (directMessages.length === 0) {
        return null;
    }

    return (
        <Section title="あなた宛ての連絡">
            <ul className="divide-y rounded-lg border">
                {directMessages.map((direct) => (
                    <li key={direct.roomId}>
                        <Link
                            href={messages.show(direct.roomId)}
                            className="hover:bg-accent focus-visible:ring-ring flex min-h-14 items-center gap-3 px-3 outline-none focus-visible:ring-[3px] focus-visible:ring-inset"
                        >
                            <MessagesSquare
                                className="text-muted-foreground size-5 shrink-0"
                                aria-hidden
                            />
                            <span className="min-w-0 flex-1 font-medium">
                                {direct.from} さんから
                            </span>
                            <span
                                className={cn(
                                    'min-w-6 rounded-full px-2 text-center text-xs leading-6 font-bold tabular-nums',
                                    COUNT_BADGE,
                                )}
                            >
                                {direct.unread}
                                <span className="sr-only">件の未読</span>
                            </span>
                            <ChevronRight
                                className="text-muted-foreground size-5 shrink-0"
                                aria-hidden
                            />
                        </Link>
                    </li>
                ))}
            </ul>
        </Section>
    );
}

/**
 * まだ「確認しました」を押していない、管理者からの周知。
 *
 * 押せば消える。無いときは何も出さない。
 */
export function AnnouncementSection({
    announcements,
}: {
    announcements: AnnouncementSummary[];
}) {
    if (announcements.length === 0) {
        return null;
    }

    return (
        <Section
            title="管理者からの周知"
            description="読んだら「確認しました」を押してください。押すとここから消えます。"
            action={
                <Link
                    href={announcementRoutes.index()}
                    className="text-sm underline underline-offset-4"
                >
                    周知の一覧
                </Link>
            }
        >
            <div className="flex flex-col gap-3">
                {announcements.map((announcement) => (
                    <AnnouncementCard
                        key={announcement.id}
                        announcement={announcement}
                        footer={
                            <ConfirmAnnouncementButton
                                announcementId={announcement.id}
                            />
                        }
                    />
                ))}
            </div>
        </Section>
    );
}
