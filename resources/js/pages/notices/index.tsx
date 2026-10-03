import { Head } from '@inertiajs/react';
import { Bell } from 'lucide-react';
import type { AnnouncementSummary } from '@/components/care/announcement-card';
import {
    AnnouncementSection,
    DirectMessageSection,
    UrgentRiskSection,
    VerbalContactSection,
} from '@/components/care/notice-lists';
import type { DirectMessage, UrgentRisk } from '@/components/care/notice-lists';
import { PageHeader } from '@/components/care/page-header';
import notices from '@/routes/notices';
import type { VerbalContact } from '@/types/care';

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
 * PC のダッシュボードにも同じ欄を出す（notice-lists.tsx）。
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

                <DirectMessageSection directMessages={directMessages} />
                <AnnouncementSection announcements={announcements} />
                <VerbalContactSection contacts={verbalContacts} />
                <UrgentRiskSection risks={risks} />
            </div>
        </>
    );
}

NoticeIndex.layout = {
    breadcrumbs: [{ title: 'お知らせ', href: notices.index() }],
};
