<?php

namespace App\Policies;

use App\Models\Message;
use App\Models\User;

/**
 * メッセージの編集。送った本人が、その部屋の参加者でいるあいだだけ直せる。
 *
 * 管理者でも他人の発言は直せない。直せてしまうと、残っている文が
 * 本人の書いたものだと言えなくなる。削除はそもそも用意していない。
 */
class MessagePolicy
{
    public function update(User $user, Message $message): bool
    {
        return $message->user_id === $user->id
            && $message->room->hasParticipant($user);
    }
}
