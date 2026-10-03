import { Form, Head, usePoll } from '@inertiajs/react';
import { Eye, History, Pencil, Send } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import MessagePostController from '@/actions/App/Http/Controllers/MessagePostController';
import { PageHeader } from '@/components/care/page-header';
import { EmptyState } from '@/components/care/section';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { NOTICE_SURFACE } from '@/lib/care-presentation';
import { cn } from '@/lib/utils';
import messages from '@/routes/messages';

type Revision = { body: string; replacedAt: string };

type ChatMessage = {
    id: number;
    body: string;
    author: string;
    isMine: boolean;
    canEdit: boolean;
    sentAt: string;
    editedAt: string | null;
    revisions: Revision[];
};

type Props = {
    room: {
        id: number;
        kind: 'facility' | 'group' | 'direct';
        kindLabel: string;
        title: string;
        members: string[];
    };
    messages: ChatMessage[];
    canPost: boolean;
    isOversight: boolean;
    accessLogs: { viewer: string; viewedAt: string }[];
};

/** 開いている部屋の新着を確かめる間隔。 */
const POLL_MS = 15_000;

/** 入力欄の共通の見た目。 */
const TEXTAREA =
    'border-input bg-background focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-base shadow-xs outline-none focus-visible:ring-2';

/**
 * 連絡の部屋。
 *
 * 【管理者が読んだことを、参加者にも見せる】
 * 管理者は参加していない部屋も読める。読まれたことが分からないままだと、
 * 職員は何がいつ見られたのか不安になる。閲覧の記録を部屋の上に出す。
 *
 * 【送信は Enter ではなくボタンで行う】
 * 日本語入力では、変換を決める Enter と送信の Enter がぶつかる。
 * 途中の文が送られると消せないので、送信はボタンだけにする。
 */
export default function MessageShow({
    room,
    messages: items,
    canPost,
    isOversight,
    accessLogs,
}: Props) {
    usePoll(POLL_MS, { only: ['messages', 'accessLogs'] });

    const bottomRef = useRef<HTMLDivElement>(null);
    const lastId = items.at(-1)?.id ?? 0;

    // 開いたときと新しい連絡が届いたときは、いちばん下まで送る。
    // 連絡は下に積まれていくので、上から読ませると最新が見えない。
    useEffect(() => {
        bottomRef.current?.scrollIntoView({ block: 'end' });
    }, [lastId]);

    return (
        <>
            <Head title={room.title} />

            <div className="flex flex-col gap-4 p-4">
                <PageHeader
                    title={room.title}
                    description={
                        room.members.length > 0
                            ? `${room.kindLabel} ／ ${room.members.join('、')}`
                            : `${room.kindLabel} ／ この事業所の職員全員`
                    }
                />

                {isOversight && (
                    <p
                        className={cn(
                            'flex items-start gap-2 rounded-md border px-3 py-2 text-sm',
                            NOTICE_SURFACE.warning,
                        )}
                    >
                        <Eye className="mt-0.5 size-4 shrink-0" aria-hidden />
                        管理者として閲覧しています。この閲覧は記録され、部屋の参加者に表示されます。書き込みはできません。
                    </p>
                )}

                {accessLogs.length > 0 && (
                    <details className="text-muted-foreground text-sm">
                        <summary className="cursor-pointer">
                            管理者の閲覧記録（最新 {accessLogs[0].viewedAt}{' '}
                            {accessLogs[0].viewer}）
                        </summary>
                        <ul className="mt-2 space-y-1 pl-4">
                            {accessLogs.map((log) => (
                                <li key={`${log.viewer}-${log.viewedAt}`}>
                                    {log.viewedAt} {log.viewer}
                                </li>
                            ))}
                        </ul>
                    </details>
                )}

                {items.length === 0 ? (
                    <EmptyState>まだ連絡はありません。</EmptyState>
                ) : (
                    <ol className="flex flex-col gap-3" aria-label="連絡">
                        {items.map((message) => (
                            <MessageItem
                                key={message.id}
                                roomId={room.id}
                                message={message}
                            />
                        ))}
                    </ol>
                )}

                <div ref={bottomRef} />

                {canPost && <Composer roomId={room.id} />}
            </div>
        </>
    );
}

function MessageItem({
    roomId,
    message,
}: {
    roomId: number;
    message: ChatMessage;
}) {
    const [editing, setEditing] = useState(false);

    return (
        <li
            className={cn(
                'flex max-w-[85%] flex-col gap-1',
                message.isMine ? 'items-end self-end' : 'items-start',
            )}
        >
            <span className="text-muted-foreground text-xs">
                {message.isMine ? 'あなた' : message.author} ／ {message.sentAt}
            </span>

            {editing ? (
                <Form
                    {...MessagePostController.update.form({
                        messageRoom: roomId,
                        message: message.id,
                    })}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setEditing(false)}
                    className="flex w-full min-w-64 flex-col gap-2"
                >
                    {({ processing, errors }) => (
                        <>
                            <textarea
                                name="body"
                                defaultValue={message.body}
                                rows={3}
                                maxLength={2000}
                                aria-label="直した文"
                                className={TEXTAREA}
                            />
                            <InputError message={errors.body} />
                            <p className="text-muted-foreground text-xs">
                                直す前の文も残り、参加者が読めます。
                            </p>
                            <div className="flex justify-end gap-2">
                                <Button
                                    type="button"
                                    variant="neutral"
                                    size="sm"
                                    onClick={() => setEditing(false)}
                                >
                                    やめる
                                </Button>
                                <Button
                                    type="submit"
                                    size="sm"
                                    pending={processing}
                                >
                                    直す
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            ) : (
                <p
                    className={cn(
                        'rounded-lg border px-3 py-2 break-words whitespace-pre-wrap',
                        message.isMine ? 'bg-accent' : 'bg-card',
                    )}
                >
                    {message.body}
                </p>
            )}

            <div className="flex flex-wrap items-center gap-2">
                {message.editedAt && (
                    <details className="text-muted-foreground text-xs">
                        <summary className="flex cursor-pointer items-center gap-1">
                            <History className="size-3.5" aria-hidden />
                            {message.editedAt} に編集（直す前の文を見る）
                        </summary>
                        <ul className="mt-1 space-y-1">
                            {message.revisions.map((revision) => (
                                <li
                                    key={revision.replacedAt + revision.body}
                                    className="bg-muted rounded px-2 py-1 whitespace-pre-wrap"
                                >
                                    {revision.body}
                                    <span className="block">
                                        {revision.replacedAt} まで
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </details>
                )}
                {message.canEdit && !editing && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() => setEditing(true)}
                    >
                        <Pencil className="size-3.5" aria-hidden />
                        直す
                    </Button>
                )}
            </div>
        </li>
    );
}

/**
 * 送信欄。消せないことを、送る前に目に入る場所に書いておく。
 */
function Composer({ roomId }: { roomId: number }) {
    return (
        <Form
            {...MessagePostController.store.form(roomId)}
            options={{ preserveScroll: true }}
            resetOnSuccess
            className="bg-background flex flex-col gap-2 border-t pt-4"
        >
            {({ processing, errors }) => (
                <>
                    <textarea
                        name="body"
                        rows={3}
                        maxLength={2000}
                        required
                        aria-label="送る内容"
                        placeholder="連絡を入力"
                        className={TEXTAREA}
                    />
                    <InputError message={errors.body} />
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <p className="text-muted-foreground text-xs">
                            送った連絡は消せません。直したときは直す前の文も残ります。
                        </p>
                        <Button type="submit" pending={processing}>
                            {!processing && (
                                <Send className="size-4" aria-hidden />
                            )}
                            送る
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

MessageShow.layout = {
    breadcrumbs: [{ title: '連絡', href: messages.index() }],
};
