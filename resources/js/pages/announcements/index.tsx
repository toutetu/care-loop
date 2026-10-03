import { Form, Head } from '@inertiajs/react';
import { CircleCheck, Megaphone, Send } from 'lucide-react';
import AnnouncementController from '@/actions/App/Http/Controllers/AnnouncementController';
import {
    AnnouncementCard,
    ConfirmAnnouncementButton,
} from '@/components/care/announcement-card';
import type { AnnouncementSummary } from '@/components/care/announcement-card';
import { PageHeader } from '@/components/care/page-header';
import { EmptyState, Section } from '@/components/care/section';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import announcements from '@/routes/announcements';

type Announcement = AnnouncementSummary & {
    isMine: boolean;
    /** 自分が「確認しました」を押した日時。押していなければ null。 */
    confirmedAt: string | null;
    targetCount: number;
    confirmed: { name: string; at: string | null }[];
    unconfirmed: string[];
};

type Props = {
    announcements: Announcement[];
    canPost: boolean;
};

/**
 * 管理者からの周知。
 *
 * 【連絡とは別の画面にする】
 * 連絡は流れていくやり取りで、周知は全員に届いたことを確かめたいもの。
 * 施設全員の部屋に流すと他の連絡に埋もれ、誰が読んだかも分からない。
 *
 * 【確認の状況は職員全員に見せる】
 * まだ確認していない人が分かれば、同じ日に出勤している職員が声をかけられる。
 */
export default function AnnouncementIndex({
    announcements: items,
    canPost,
}: Props) {
    return (
        <>
            <Head title="周知" />

            <div className="flex flex-col gap-4 p-4">
                <PageHeader
                    icon={Megaphone}
                    title="周知"
                    description="管理者から職員全員へのお知らせです。読んだら「確認しました」を押してください。"
                />

                {canPost && <AnnouncementForm />}

                <Section title="これまでの周知">
                    {items.length === 0 ? (
                        <EmptyState>まだ周知はありません。</EmptyState>
                    ) : (
                        <div className="flex flex-col gap-3">
                            {items.map((announcement) => (
                                <AnnouncementCard
                                    key={announcement.id}
                                    announcement={announcement}
                                    footer={
                                        <AnnouncementStatus
                                            announcement={announcement}
                                        />
                                    }
                                />
                            ))}
                        </div>
                    )}
                </Section>
            </div>
        </>
    );
}

/**
 * 自分の確認と、全体の確認の状況。
 */
function AnnouncementStatus({ announcement }: { announcement: Announcement }) {
    const done = announcement.confirmed.length;

    return (
        <>
            {announcement.isMine ? (
                <p className="text-muted-foreground border-t pt-3 text-sm">
                    あなたが出した周知です。
                </p>
            ) : announcement.confirmedAt ? (
                <p className="flex items-center gap-1.5 border-t pt-3 text-sm">
                    <CircleCheck className="size-4" aria-hidden />
                    {announcement.confirmedAt} に確認しました
                </p>
            ) : (
                <ConfirmAnnouncementButton announcementId={announcement.id} />
            )}

            <details className="text-sm">
                <summary className="cursor-pointer">
                    確認した職員 {done} ／ {announcement.targetCount} 名
                    {announcement.unconfirmed.length > 0 &&
                        `（まだの方：${announcement.unconfirmed.join('、')}）`}
                </summary>
                {done > 0 && (
                    <ul className="text-muted-foreground mt-2 space-y-1 pl-4">
                        {announcement.confirmed.map((person) => (
                            <li key={person.name}>
                                {person.name}　{person.at}
                            </li>
                        ))}
                    </ul>
                )}
            </details>
        </>
    );
}

/** 周知を出す。管理者以上だけに見せる（AnnouncementPolicy）。 */
function AnnouncementForm() {
    return (
        <Section
            title="周知を出す"
            description="出した周知は直せません。訂正は新しい周知として出してください。職員が何を確認したのかが分からなくなるためです。"
        >
            <Form
                {...AnnouncementController.store.form()}
                resetOnSuccess
                className="flex flex-col gap-4"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-2">
                            <Label htmlFor="announcement-title">件名</Label>
                            <Input
                                id="announcement-title"
                                name="title"
                                maxLength={100}
                                required
                                placeholder="例：送迎車内の換気について"
                            />
                            <InputError message={errors.title} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="announcement-body">本文</Label>
                            <textarea
                                id="announcement-body"
                                name="body"
                                rows={5}
                                maxLength={3000}
                                required
                                className="border-input bg-background focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-base shadow-xs outline-none focus-visible:ring-2"
                            />
                            <InputError message={errors.body} />
                        </div>

                        <label className="flex min-h-11 items-center gap-3">
                            <Checkbox name="is_important" value="1" />
                            重要（お知らせの先頭に、色を付けて出します）
                        </label>

                        <Button
                            type="submit"
                            pending={processing}
                            className="self-start"
                        >
                            {!processing && (
                                <Send className="size-4" aria-hidden />
                            )}
                            周知を出す
                        </Button>
                    </>
                )}
            </Form>
        </Section>
    );
}

AnnouncementIndex.layout = {
    breadcrumbs: [{ title: '周知', href: announcements.index() }],
};
