<?php

namespace App\Policies;

use App\Models\User;

/**
 * 職員アカウントの権限（要件定義 4.2節）。
 *
 * 【管理者に限る】
 * アカウントを作れるということは、記録を書ける人を増やせるということである。
 * 介護記録は法定の保存文書であり、誰が書いたかを追えることが前提になる。
 * 権限の付与を現場の判断で行える状態にはしない（UserRole::canManageUsers）。
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->facility_id !== null && $user->role->canManageUsers();
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    /**
     * 職員情報の変更。
     *
     * 【自分自身の役割は変えられない】
     * 管理者が誤って自分を介護職員に落とすと、誰も権限を戻せなくなる。
     * 事業所に管理者が1人しかいない状況は珍しくない。
     * 自分の氏名やメールアドレスは設定画面から変更できる。
     */
    public function update(User $user, User $target): bool
    {
        return $this->sameFacility($user, $target)
            && $user->role->canManageUsers()
            && $user->id !== $target->id;
    }

    private function sameFacility(User $user, User $target): bool
    {
        return $user->facility_id !== null
            && $user->facility_id === $target->facility_id;
    }
}
