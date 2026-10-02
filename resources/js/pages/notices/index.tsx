import { Head } from '@inertiajs/react';
import { Bell } from 'lucide-react';
import {
    UrgentRiskSection,
    VerbalContactSection,
} from '@/components/care/notice-lists';
import type { UrgentRisk } from '@/components/care/notice-lists';
import { PageHeader } from '@/components/care/page-header';
import notices from '@/routes/notices';
import type { VerbalContact } from '@/types/care';

type Props = {
    risks: UrgentRisk[];
    verbalContacts: VerbalContact[];
};

/**
 * お知らせ。スマートフォンの下のバーから開く。
 *
 * 【お迎えの事項を上に置く】
 * 送迎の時刻は決まっていて、過ぎてしまえばその日のうちには伝えられない。
 * リスクの指摘は根拠を読んで判断するもので、数分を争うものではない。
 * 期限のあるほうを先に目に入れる。
 */
export default function NoticeIndex({ risks, verbalContacts }: Props) {
    return (
        <>
            <Head title="お知らせ" />

            <div className="flex flex-col gap-4 p-4">
                <PageHeader
                    icon={Bell}
                    title="お知らせ"
                    description="いま気をつけることと、ご家族へお伝えすることです。"
                />

                <VerbalContactSection contacts={verbalContacts} />
                <UrgentRiskSection risks={risks} />
            </div>
        </>
    );
}

NoticeIndex.layout = {
    breadcrumbs: [{ title: 'お知らせ', href: notices.index() }],
};
