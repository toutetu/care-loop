import { Form } from '@inertiajs/react';
import { CircleCheck, Megaphone } from 'lucide-react';
import type { ReactNode } from 'react';
import AnnouncementController from '@/actions/App/Http/Controllers/AnnouncementController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { IMPORTANT_BADGE } from '@/lib/care-presentation';
import { cn } from '@/lib/utils';

export type AnnouncementSummary = {
    id: number;
    title: string;
    body: string;
    isImportant: boolean;
    author: string;
    postedAt: string;
};

/**
 * 周知1件。お知らせの画面と周知の一覧で同じ見た目にする。
 *
 * 【本文を畳まない】
 * 「確認しました」は読んだうえで押すものなので、件名だけ見せて押せる作りに
 * しない。本文は最初から全部見せ、そのすぐ下にボタンを置く。
 */
export function AnnouncementCard({
    announcement,
    footer,
}: {
    announcement: AnnouncementSummary;
    /** 確認の状況。周知の一覧だけで出す。 */
    footer?: ReactNode;
}) {
    return (
        <article className="flex flex-col gap-2 rounded-lg border p-4">
            <header className="flex flex-col gap-1">
                <div className="flex flex-wrap items-center gap-2">
                    <Megaphone
                        className="text-muted-foreground size-4 shrink-0"
                        aria-hidden
                    />
                    {announcement.isImportant && (
                        <Badge className={IMPORTANT_BADGE}>重要</Badge>
                    )}
                    <h3 className="text-base font-semibold">
                        {announcement.title}
                    </h3>
                </div>
                <p className="text-muted-foreground text-xs">
                    {announcement.author} ／ {announcement.postedAt}
                </p>
            </header>

            <p className="leading-relaxed break-words whitespace-pre-wrap">
                {announcement.body}
            </p>

            {footer}
        </article>
    );
}

/**
 * 「確認しました」のボタン。押した記録が残ることを、ボタンの横に書いておく。
 */
export function ConfirmAnnouncementButton({
    announcementId,
    className,
}: {
    announcementId: number;
    className?: string;
}) {
    return (
        <Form
            {...AnnouncementController.confirm.form(announcementId)}
            options={{ preserveScroll: true }}
            className={cn(
                'flex flex-wrap items-center gap-x-3 gap-y-1 border-t pt-3',
                className,
            )}
        >
            {({ processing }) => (
                <>
                    <Button type="submit" pending={processing}>
                        {!processing && (
                            <CircleCheck className="size-4" aria-hidden />
                        )}
                        確認しました
                    </Button>
                    <span className="text-muted-foreground text-xs">
                        押した日時が残り、管理者と職員から見えます。
                    </span>
                </>
            )}
        </Form>
    );
}
