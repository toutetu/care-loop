<?php

namespace App\Policies;

use App\Models\Announcement;
use App\Models\User;

/**
 * 周知の権限。
 *
 * 出せるのは管理者以上、確認するのは同じ事業所の職員。誰が確認したかの
 * 一覧も同じ事業所の職員なら見られる。確認していない人を責めるためではなく、
 * 「まだ伝わっていない人がいる」ことに周りが気づけるようにする。
 */
class AnnouncementPolicy
{
    public function create(User $user): bool
    {
        return $user->role->canPostAnnouncements();
    }

    public function confirm(User $user, Announcement $announcement): bool
    {
        return $user->facility_id === $announcement->facility_id;
    }
}
