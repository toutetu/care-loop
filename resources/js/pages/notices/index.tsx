import { Head, Link } from '@inertiajs/react';
import { Bell, ChevronRight, MessagesSquare } from 'lucide-react';
import {
    AnnouncementCard,
    ConfirmAnnouncementButton,
} from '@/components/care/announcement-card';
import type { AnnouncementSummary } from '@/components/care/announcement-card';
import {
    UrgentRiskSection,
    VerbalContactSection,
} from '@/components/care/notice-lists';
import type { UrgentRisk } from '@/components/care/notice-lists';
import { PageHeader } from '@/components/care/page-header';
import { Section } from '@/components/care/section';
import { COUNT_BADGE } from '@/lib/care-presentation';
import { cn } from '@/lib/utils';
import announcementRoutes from '@/routes/announcements';
import messages from '@/routes/messages';
import notices from '@/routes/notices';
import type { VerbalContact } from '@/types/care';

type DirectMessage = { roomId: number; from: string; unread: number };

type Props = {
    risks: UrgentRisk[];
    verbalContacts: VerbalContact[];
    directMessages: DirectMessage[];
    /** まだ「確認しました」を押していない周知。重要なものが先。 */
    announcements: AnnouncementSummary[];
};

/**
 * お知らせ。スマートフォンの下のバーから開く。
 *
 * 【お迎えの事項を上に置く】
 * 送迎の時刻は決まっていて、過ぎてしまえばその日のうちには伝えられない。
 * リスクの指摘は根拠を読んで判断するもので、数分を争うものではない。
 * 期限のあるほうを先に目に入れる。
 *
 * 【自分宛ての連絡は、あるときだけ出す】
 * 個別の連絡は自分にしか届かないので、読み落とすと誰も気づかない。
 * ただし無いときに空の枠を出すと、毎回読み飛ばす場所が1つ増える。
 * 管理者からの周知も同じで、確認を済ませれば消える。
 */
export default function NoticeIndex({
    risks,
    verbalContacts,
    directMessages,
    announcements,
}: Props) {
    return (
        <>
            <Head title="お知らせ" />

            <div className="flex flex-col gap-4 p-4">
                <PageHeader
                    icon={Bell}
                    title="お知らせ"
                    description="いま気をつけることと、ご家族へお伝えすることです。"
                />

                {directMessages.length > 0 && (
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
                                            <span className="sr-only">
                                                件の未読
                                            </span>
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
                )}

                {announcements.length > 0 && (
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
                )}

                <VerbalContactSection contacts={verbalContacts} />
                <UrgentRiskSection risks={risks} />
            </div>
        </>
    );
}

NoticeIndex.layout = {
    breadcrumbs: [{ title: 'お知らせ', href: notices.index() }],
};
