<?php

namespace App\Support;

use App\Enums\MessageRoomKind;
use App\Models\MessageRoom;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 1人の職員から見た連絡。参加している部屋と、まだ読んでいない数。
 *
 * 【未読は「どこまで読んだか」から数える】
 * 1件ずつ既読を付けると、施設全員の部屋では職員の数だけ行が増える。
 * 部屋ごとに最後に読んだメッセージを1つ持ち、それより後で他の職員が
 * 送ったものを未読とする。
 */
class MessageInbox
{
    public function __construct(private readonly User $user) {}

    /**
     * 参加している部屋。施設全員の部屋を先頭に置く。
     *
     * @return Collection<int, MessageRoom>
     */
    public function rooms(): Collection
    {
        // 事業所に属していないアカウントには、連絡する相手がいない
        if ($this->user->facility_id === null) {
            return new Collection;
        }

        $facilityRoom = MessageRoom::forFacility($this->user->facility_id);

        $memberRooms = MessageRoom::query()
            ->where('facility_id', $this->user->facility_id)
            ->where('kind', '<>', MessageRoomKind::Facility)
            ->whereHas('members', fn ($query) => $query->whereKey($this->user->id))
            ->with('members')
            ->get();

        return new Collection([$facilityRoom, ...$memberRooms->all()]);
    }

    /**
     * 部屋ごとの未読の数。未読がない部屋は含まない。
     *
     * @param  list<int>  $roomIds
     * @return array<int, int>
     */
    public function unreadCounts(array $roomIds): array
    {
        if ($roomIds === []) {
            return [];
        }

        $userId = $this->user->id;

        return DB::table('messages')
            ->leftJoin('message_room_members as reader', function ($join) use ($userId): void {
                $join->on('reader.message_room_id', '=', 'messages.message_room_id')
                    ->where('reader.user_id', '=', $userId);
            })
            ->whereIn('messages.message_room_id', $roomIds)
            // 自分が送ったものは未読にしない
            ->where(fn ($query) => $query
                ->whereNull('messages.user_id')
                ->orWhere('messages.user_id', '<>', $userId))
            ->whereRaw('messages.id > COALESCE(reader.last_read_message_id, 0)')
            ->groupBy('messages.message_room_id')
            ->selectRaw('messages.message_room_id as room_id, COUNT(*) as unread')
            ->pluck('unread', 'room_id')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }

    /**
     * 未読の合計。下のバーの「連絡」に出す。
     *
     * 画面を開くたびに数えるので、部屋を作らずに今ある部屋だけを見る。
     */
    public function unreadTotal(): int
    {
        return array_sum($this->unreadCounts($this->participatingRoomIds()));
    }

    /**
     * 自分宛ての個別の連絡で、まだ読んでいないもの。お知らせに出す。
     *
     * 施設全員やグループの連絡は「連絡」の件数で足りる。個別の連絡は
     * 自分にしか届かないので、読み落とすと誰も気づかない。
     *
     * @return list<array{roomId: int, from: string, unread: int}>
     */
    public function unreadDirect(): array
    {
        $rooms = MessageRoom::query()
            ->where('facility_id', $this->user->facility_id)
            ->where('kind', MessageRoomKind::Direct)
            ->whereHas('members', fn ($query) => $query->whereKey($this->user->id))
            ->with('members')
            ->get();

        $counts = $this->unreadCounts(self::idsOf($rooms));

        return array_values($rooms
            ->filter(fn (MessageRoom $room): bool => ($counts[$room->id] ?? 0) > 0)
            ->map(fn (MessageRoom $room): array => [
                'roomId' => $room->id,
                'from' => $this->otherMember($room),
                'unread' => $counts[$room->id],
            ])
            ->all());
    }

    /**
     * ここまで読んだ、と残す。
     *
     * 参加者でなければ何もしない。「どこまで読んだか」はグループの参加者と
     * 同じ表に持つので、管理者の閲覧などで行を作ると、参加していない人が
     * 参加者として数えられてしまう。
     */
    public function markRead(MessageRoom $room): void
    {
        if (! $room->hasParticipant($this->user)) {
            return;
        }

        $latest = $room->messages()->max('id');

        if ($latest === null) {
            return;
        }

        DB::table('message_room_members')->updateOrInsert(
            ['message_room_id' => $room->id, 'user_id' => $this->user->id],
            ['last_read_message_id' => $latest, 'updated_at' => now()],
        );
    }

    /**
     * 部屋の名前。個別の部屋は相手の名前にする。
     */
    public function titleOf(MessageRoom $room): string
    {
        return match ($room->kind) {
            MessageRoomKind::Facility => '施設全員',
            MessageRoomKind::Group => (string) $room->name,
            MessageRoomKind::Direct => $this->otherMember($room).' さん',
        };
    }

    private function otherMember(MessageRoom $room): string
    {
        $other = $room->members->firstWhere('id', '<>', $this->user->id);

        return $other !== null ? $other->name : '（退職した職員）';
    }

    /** @return list<int> */
    private function participatingRoomIds(): array
    {
        return self::idsOf(MessageRoom::query()
            ->where('facility_id', $this->user->facility_id)
            ->where(fn ($query) => $query
                ->where('kind', MessageRoomKind::Facility)
                ->orWhereHas('members', fn ($members) => $members->whereKey($this->user->id)))
            ->get(['id']));
    }

    /**
     * 部屋の ID の並び。未読の集計や最新の1件を引くときに渡す。
     *
     * @param  iterable<MessageRoom>  $rooms
     * @return list<int>
     */
    public static function idsOf(iterable $rooms): array
    {
        $ids = [];

        foreach ($rooms as $room) {
            $ids[] = $room->id;
        }

        return $ids;
    }
}
