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
