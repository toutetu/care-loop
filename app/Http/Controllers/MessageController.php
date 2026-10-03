<?php

namespace App\Http\Controllers;

use App\Enums\MessageRoomKind;
use App\Models\Message;
use App\Models\MessageRoom;
use App\Models\User;
use App\Support\MessageInbox;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 連絡（職員どうしのメッセージ）の部屋の一覧。
 *
 * 【個人の連絡アプリの代わりになるように】
 * 現場の連絡が個人の LINE に流れると、ご利用者の様子が業務の外に残り、
 * 行き違いも後から確かめられない。施設全員・グループ・個別のどれでも
 * やり取りでき、すべて事業所の記録として残る場所を用意する。
 *
 * 【管理者には参加していない部屋も並べる】
 * 「管理者が後から読める」と承諾してもらっている以上、その入口を
 * 用意しておく。開くと閲覧の記録が残り、参加者にも見える
 * （MessageRoomController::show）。
 */
class MessageController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $inbox = new MessageInbox($user);

        $rooms = $inbox->rooms();
        $unread = $inbox->unreadCounts(MessageInbox::idsOf($rooms));
        $latest = $this->latestMessages(MessageInbox::idsOf($rooms));

        $rows = $rooms
            ->map(fn (MessageRoom $room): array => [
                'id' => $room->id,
                'kind' => $room->kind->value,
                'kindLabel' => $room->kind->label(),
                'title' => $inbox->titleOf($room),
                'memberCount' => $room->isFacilityWide() ? null : $room->members->count(),
                'unread' => $unread[$room->id] ?? 0,
                'lastMessage' => $this->summary($latest->get($room->id)),
                'lastMessageId' => $latest->get($room->id)->id ?? 0,
            ])
            // 施設全員の部屋は動きがなくても先頭に置く。全員への連絡は
            // いつも同じ場所にあるほうが探さずに済む。そのあとは新しい順。
            ->sortByDesc(fn (array $row): array => [$row['kind'] === MessageRoomKind::Facility->value ? 1 : 0, $row['lastMessageId']])
            ->values();

        return Inertia::render('messages/index', [
            'rooms' => $rows->all(),
            'colleagues' => $this->colleagues($user),
            'canCreateGroup' => $user->can('createGroup', MessageRoom::class),
            'canOversee' => $user->role->canOverseeMessages(),
            'oversightRooms' => $user->role->canOverseeMessages()
                ? $this->oversightRooms($user, MessageInbox::idsOf($rooms))
                : [],
        ]);
    }

    /**
     * 部屋ごとの最新の1件。
     *
     * @param  list<int>  $roomIds
     * @return Collection<int, Message>
     */
    private function latestMessages(array $roomIds): Collection
    {
        $ids = Message::query()
            ->whereIn('message_room_id', $roomIds)
            ->groupBy('message_room_id')
            ->selectRaw('MAX(id) as id')
            ->pluck('id');

        return Message::query()
            ->whereIn('id', $ids)
            ->with('author')
            ->get()
            ->keyBy('message_room_id');
    }

    /**
     * 一覧に出す最新の1件。本文は書き出しだけにする。
     *
     * @return array<string, string>|null
     */
    private function summary(?Message $message): ?array
    {
        if ($message === null) {
            return null;
        }

        return [
            'preview' => Str::limit($message->body, 40),
            'author' => $message->author !== null ? $message->author->name : '（退職した職員）',
            'sentAt' => $message->created_at->translatedFormat('n/j H:i'),
        ];
    }

    /**
     * 個別の連絡やグループに選べる職員。在籍中で同じ事業所の人だけ。
     *
     * @return list<array<string, mixed>>
     */
    private function colleagues(User $user): array
    {
        return array_values(User::query()
            ->where('facility_id', $user->facility_id)
            ->where('is_active', true)
            ->whereKeyNot($user->id)
            ->orderBy('id')
            ->get()
            ->map(fn (User $colleague): array => [
                'id' => $colleague->id,
                'name' => $colleague->name,
                'roleLabel' => $colleague->role->label(),
            ])
            ->all());
    }

    /**
     * 管理者が参加していない部屋。後から確認するための入口。
     *
     * @param  list<int>  $participating
     * @return list<array<string, mixed>>
     */
    private function oversightRooms(User $user, array $participating): array
    {
        $rooms = MessageRoom::query()
            ->where('facility_id', $user->facility_id)
            ->where('kind', '<>', MessageRoomKind::Facility)
            ->whereKeyNot($participating)
            ->with('members')
            ->withCount('messages')
            ->get();

        $latest = $this->latestMessages(MessageInbox::idsOf($rooms));

        return array_values($rooms
            ->sortByDesc(fn (MessageRoom $room): int => $latest->get($room->id)->id ?? 0)
            ->map(fn (MessageRoom $room): array => [
                'id' => $room->id,
                'kindLabel' => $room->kind->label(),
                'title' => $room->kind === MessageRoomKind::Group
                    ? (string) $room->name
                    : $room->members->pluck('name')->join(' さんと ').' さん',
                'members' => $room->members->pluck('name')->all(),
                'messageCount' => (int) $room->getAttribute('messages_count'),
                'lastSentAt' => $latest->get($room->id)?->created_at->translatedFormat('n/j H:i'),
            ])
            ->all());
    }
}
