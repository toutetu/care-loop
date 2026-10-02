import { Head } from '@inertiajs/react';
import { MessagesSquare } from 'lucide-react';
import { PageHeader } from '@/components/care/page-header';
import { EmptyState, Section } from '@/components/care/section';
import messages from '@/routes/messages';

/**
 * 連絡（職員間のメッセージ）。いまは準備中。
 *
 * 下のバーの並びを先に決めた。並びがあとから変わると、職員は押す場所を
 * 覚え直すことになる。押しても何も起きないボタンは「壊れている」と
 * 受け取られるので、準備中であることと、できるようになることを書いておく。
 */
export default function MessageIndex() {
    return (
        <>
            <Head title="連絡" />

            <div className="flex flex-col gap-4 p-4">
                <PageHeader
                    icon={MessagesSquare}
                    title="連絡"
                    description="職員どうしの連絡をここでやり取りします。"
                />

                <Section title="準備中です">
                    <EmptyState>
                        施設全員・グループ・個別でやり取りできるようになります。
                        やり取りは業務の記録として残り、管理者が後から確認できます。
                    </EmptyState>
                </Section>
            </div>
        </>
    );
}

MessageIndex.layout = {
    breadcrumbs: [{ title: '連絡', href: messages.index() }],
};
