<?php

namespace App\Policies;

use App\Models\User;

/**
 * 編集履歴の権限（要件定義 4.2節）。
 *
 * 【管理者に限る】
 * 履歴には変更前の値が含まれる。ご利用者の旧住所や旧連絡先まで見えるため、
 * 日々の介護業務で開く必要はない。閲覧できる人を絞ることが保護になる。
 *
 * 【書き換える手段を用意しない】
 * update も delete も定義しない。後から都合よく直せる履歴は、
 * 監査の役に立たない。保存期間を過ぎた分の削除は、画面ではなく
 * バッチで行う（要件定義 8章 削除の方針）。
 */
class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->facility_id !== null && $user->role->canManageUsers();
    }
}
