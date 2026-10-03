import { Form, Head, Link, usePoll } from '@inertiajs/react';
import { ChevronRight, Eye, MessagesSquare, Send, Users } from 'lucide-react';
import { useState } from 'react';
import MessageRoomController from '@/actions/App/Http/Controllers/MessageRoomController';
import { PageHeader } from '@/components/care/page-header';
import { EmptyState, Section } from '@/components/care/section';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { COUNT_BADGE } from '@/lib/care-presentation';
import { cn } from '@/lib/utils';
import messages from '@/routes/messages';

type Room = {
    id: number;
    kind: 'facility' | 'group' | 'direct';
    kindLabel: string;
    title: string;
    memberCount: number | null;
    unread: number;
    lastMessage: { preview: string; author: string; sentAt: string } | null;
};

type Colleague = { id: number; name: string; roleLabel: string };

type OversightRoom = {
    id: number;
    kindLabel: string;
    title: string;
    members: string[];
    messageCount: number;
    lastSentAt: string | null;
};

type Props = {
    rooms: Room[];
    colleagues: Colleague[];
    canCreateGroup: boolean;
    /** 参加していない部屋も読めるか（管理者以上） */
    canOversee: boolean;
    oversightRooms: OversightRoom[];
};

/** 未読の確認の間隔。開いている部屋（15秒）よりは緩くてよい。 */
const POLL_MS = 30_000;

/**
 * 連絡の部屋の一覧。
 *
 * 【新着は数十秒ごとに確かめる】
 * 即時に届ける仕組み（WebSocket）は、常に接続を保つサーバーが別に要る。
 * 介護の連絡は秒を争うものではなく、急ぎは電話か声で伝える。1台の
 * サーバーのまま動かせることを優先し、開いているあいだだけ読み直す。
 */
export default function MessageIndex({
    rooms,
    colleagues,
    canCreateGroup,
    canOversee,
    oversightRooms,
}: Props) {
    usePoll(POLL_MS, { only: ['rooms', 'oversightRooms', 'messageUnread'] });

    return (
        <>
            <Head title="連絡" />

            <div className="flex flex-col gap-4 p-4">
                <PageHeader
                    icon={MessagesSquare}
                    title="連絡"
                    description="職員どうしの連絡です。やり取りは業務の記録として残ります。"
                />

                <Section title="部屋">
                    <ul className="divide-y rounded-lg border">
                        {rooms.map((room) => (
                            <li key={room.id}>
                                <RoomLink room={room} />
                            </li>
                        ))}
                    </ul>
                </Section>

                <DirectMessageStarter colleagues={colleagues} />

                {canCreateGroup && <GroupCreator colleagues={colleagues} />}

                {canOversee && (
                    <Section
                        title="管理者の確認用"
                        description="あなたが参加していない部屋です。開くと閲覧の記録が残り、その部屋の参加者に表示されます。"
                    >
                        {oversightRooms.length === 0 ? (
                            <EmptyState>
                                参加していない部屋はありません。
                            </EmptyState>
                        ) : (
                            <ul className="divide-y rounded-lg border">
                                {oversightRooms.map((room) => (
                                    <li key={room.id}>
                                        <Link
                                            href={messages.show(room.id)}
                                            className="hover:bg-accent focus-visible:ring-ring flex min-h-14 items-center gap-3 px-3 py-2 outline-none focus-visible:ring-[3px] focus-visible:ring-inset"
                                        >
                                            <Eye
                                                className="text-muted-foreground size-5 shrink-0"
                                                aria-hidden
                                            />
                                            <span className="min-w-0 flex-1">
                                                <span className="flex flex-wrap items-center gap-2">
                                                    <span className="font-medium">
                                                        {room.title}
                                                    </span>
                                                    <Badge variant="outline">
                                                        {room.kindLabel}
                                                    </Badge>
                                                </span>
                                                <span className="text-muted-foreground block text-sm">
                                                    {room.messageCount} 件
                                                    {room.lastSentAt &&
                                                        ` ／ 最終 ${room.lastSentAt}`}
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
                        )}
                    </Section>
                )}
            </div>
        </>
    );
}

function RoomLink({ room }: { room: Room }) {
    return (
        <Link
            href={messages.show(room.id)}
            className="hover:bg-accent focus-visible:ring-ring flex min-h-16 items-center gap-3 px-3 py-2 outline-none focus-visible:ring-[3px] focus-visible:ring-inset"
        >
            <span className="min-w-0 flex-1">
                <span className="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <span className="text-base font-semibold">
                        {room.title}
                    </span>
                    {/* 施設全員と個別は、名前を見れば種類が分かる */}
                    {room.kind === 'group' && (
                        <Badge variant="outline" className="gap-1">
                            <Users className="size-3" aria-hidden />
                            {room.kindLabel}
                            {room.memberCount !== null &&
                                ` ${room.memberCount}名`}
                        </Badge>
                    )}
                </span>
                <span className="text-muted-foreground block truncate text-sm">
                    {room.lastMessage
                        ? `${room.lastMessage.author}：${room.lastMessage.preview}`
                        : 'まだ連絡はありません'}
                </span>
            </span>
            <span className="flex shrink-0 flex-col items-end gap-1">
                {room.lastMessage && (
                    <span className="text-muted-foreground text-xs tabular-nums">
                        {room.lastMessage.sentAt}
                    </span>
                )}
                {room.unread > 0 && (
                    <span
                        className={cn(
                            'min-w-6 rounded-full px-2 text-center text-xs leading-6 font-bold tabular-nums',
                            COUNT_BADGE,
                        )}
                    >
                        {room.unread}
                        <span className="sr-only">件の未読</span>
                    </span>
                )}
            </span>
        </Link>
    );
}

/**
 * 個別の連絡を始める。
 *
 * 端末の選択欄（select）を使う。職員が数十人いても、スマートフォンでは
 * OS の選び方で探せる。
 */
function DirectMessageStarter({ colleagues }: { colleagues: Colleague[] }) {
    return (
        <Section
            title="個別に連絡する"
            description="全員に流すほどではない連絡に使います。同じ相手とは同じ部屋になります。"
        >
            {colleagues.length === 0 ? (
                <EmptyState>連絡できる職員がいません。</EmptyState>
            ) : (
                <Form
                    {...MessageRoomController.direct.form()}
                    className="flex flex-col gap-2 sm:flex-row sm:items-start"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="flex-1">
                                <Label
                                    htmlFor="direct-user"
                                    className="sr-only"
                                >
                                    相手の職員
                                </Label>
                                <select
                                    id="direct-user"
                                    name="user_id"
                                    defaultValue=""
                                    required
                                    className="border-input bg-background focus-visible:ring-ring h-11 w-full rounded-md border px-3 text-base shadow-xs outline-none focus-visible:ring-2"
                                >
                                    <option value="" disabled>
                                        相手を選んでください
                                    </option>
                                    {colleagues.map((colleague) => (
                                        <option
                                            key={colleague.id}
                                            value={colleague.id}
                                        >
                                            {colleague.name}（
                                            {colleague.roleLabel}）
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.user_id} />
                            </div>
                            <Button type="submit" pending={processing}>
                                {!processing && (
                                    <Send className="size-4" aria-hidden />
                                )}
                                連絡を始める
                            </Button>
                        </>
                    )}
                </Form>
            )}
        </Section>
    );
}

/** グループを作る。係の分け方は管理者が決める（MessageRoomPolicy）。 */
function GroupCreator({ colleagues }: { colleagues: Colleague[] }) {
    const [selected, setSelected] = useState<number[]>([]);

    const toggle = (id: number) =>
        setSelected((current) =>
            current.includes(id)
                ? current.filter((value) => value !== id)
                : [...current, id],
        );

    return (
        <Section
            title="グループを作る"
            description="入浴担当・送迎担当のように、係の中で済む連絡に使います。あなたも参加者に入ります。"
        >
            <Form
                {...MessageRoomController.storeGroup.form()}
                className="flex flex-col gap-4"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-2">
                            <Label htmlFor="group-name">グループの名前</Label>
                            <Input
                                id="group-name"
                                name="name"
                                maxLength={50}
                                placeholder="例：入浴担当"
                                required
                            />
                            <InputError message={errors.name} />
                        </div>

                        <fieldset className="grid gap-2">
                            <legend className="mb-2 text-sm font-medium">
                                参加する職員
                            </legend>
                            <div className="grid gap-2 sm:grid-cols-2">
                                {colleagues.map((colleague) => (
                                    <label
                                        key={colleague.id}
                                        className="hover:bg-accent flex min-h-11 items-center gap-3 rounded-md border px-3"
                                    >
                                        <Checkbox
                                            checked={selected.includes(
                                                colleague.id,
                                            )}
                                            onCheckedChange={() =>
                                                toggle(colleague.id)
                                            }
                                        />
                                        {colleague.name}
                                        <span className="text-muted-foreground text-xs">
                                            {colleague.roleLabel}
                                        </span>
                                    </label>
                                ))}
                            </div>
                            {selected.map((id) => (
                                <input
                                    key={id}
                                    type="hidden"
                                    name="member_ids[]"
                                    value={id}
                                />
                            ))}
                            <InputError message={errors.member_ids} />
                        </fieldset>

                        <Button
                            type="submit"
                            pending={processing}
                            disabled={processing || selected.length === 0}
                            className="self-start"
                        >
                            グループを作る
                        </Button>
                    </>
                )}
            </Form>
        </Section>
    );
}

MessageIndex.layout = {
    breadcrumbs: [{ title: '連絡', href: messages.index() }],
};
