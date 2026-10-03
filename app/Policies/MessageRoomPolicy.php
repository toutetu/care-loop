<?php

namespace App\Policies;

use App\Models\MessageRoom;
use App\Models\User;

/**
 * 連絡の部屋の権限。
 *
 * 読めるのは参加者と、同じ事業所の管理者以上。書けるのは参加者だけにする。
 * 管理者が参加していない部屋に書き込めると、「後から確認する人」が
 * 会話の当事者になってしまい、確認の中立性が崩れる。
 *
 * 事業所の境界は越えない。他の事業所の職員の連絡を読んでよい理由はない。
 */
class MessageRoomPolicy
{
    public function view(User $user, MessageRoom $room): bool
    {
        if ($user->facility_id !== $room->facility_id) {
            return false;
        }

        return $room->hasParticipant($user) || $user->role->canOverseeMessages();
    }

    public function post(User $user, MessageRoom $room): bool
    {
        return $room->hasParticipant($user);
    }

    public function createGroup(User $user): bool
    {
        return $user->role->canCreateMessageGroups();
    }
}
