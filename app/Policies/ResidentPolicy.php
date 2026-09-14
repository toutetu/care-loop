<?php

namespace App\Policies;

use App\Models\Resident;
use App\Models\User;

/**
 * ご利用者情報の権限（要件定義 4.2節）。
 *
 * 事業所をまたいだ参照を禁じる。氏名・保険者番号・既往歴を含むため、
 * 所属していない事業所のご利用者を見られてよい理由がない。
 */
class ResidentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->facility_id !== null;
    }

    public function view(User $user, Resident $resident): bool
    {
        return $this->sameFacility($user, $resident);
    }

    /**
     * ご利用者の登録。
     *
     * 【介護職員には許さない】
     * 新規のご利用者を迎えるのは契約の手続きであり、生活相談員か管理者の
     * 仕事である。フロアの職員が登録できる必要はなく、できてしまうと
     * 二重登録や書きかけの登録が増える（UserRole::canManageResidents）。
     */
    public function create(User $user): bool
    {
        return $user->facility_id !== null && $user->role->canManageResidents();
    }

    /** ご利用者情報の編集。登録と同じ権限で扱う。 */
    public function update(User $user, Resident $resident): bool
    {
        return $this->sameFacility($user, $resident) && $user->role->canManageResidents();
    }

    /**
     * AI機能の実行。
     *
     * 閲覧できる方に対してのみ実行を許す。実行のたびに費用が発生するため、
     * 誰がどのご利用者に対して呼び出したのかを追跡できる状態にしておく
     * （llm_jobs.requested_by）。
     */
    public function runLlm(User $user, Resident $resident): bool
    {
        return $this->sameFacility($user, $resident);
    }

    private function sameFacility(User $user, Resident $resident): bool
    {
        return $user->facility_id !== null
            && $user->facility_id === $resident->facility_id;
    }
}
