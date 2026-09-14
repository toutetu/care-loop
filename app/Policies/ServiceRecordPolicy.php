<?php

namespace App\Policies;

use App\Models\ServiceRecord;
use App\Models\User;

/**
 * サービス提供記録の権限（要件定義 4.2節）。
 *
 * 事業所をまたいだ参照を禁じる。介護記録は要配慮個人情報であり、
 * 所属していない事業所のご利用者の記録を見られてよい理由がない。
 */
class ServiceRecordPolicy
{
    public function view(User $user, ServiceRecord $record): bool
    {
        return $this->sameFacility($user, $record);
    }

    /**
     * 記録の入力・編集。同じ事業所の職員なら誰でもできる。
     *
     * 【記録者による制限を外した理由】
     * 以前は「自分が記録したものだけ」に限っていた。しかし通所介護の現場では、
     * 送迎・入浴・食事・帰宅と担当が入れ替わり、1人のご利用者の1日を複数の
     * 職員が分担して記録する。最初に触れた職員しか書けない作りでは、
     * 入浴を担当した職員が入浴の記録を残せない。
     *
     * 誰が何を入力したかは記録側に残す。書ける人を狭めるのではなく、
     * 書いた人が分かるようにすることで責任の所在を保つ。
     *
     * 事業所の境界は残す。介護記録は要配慮個人情報であり、所属していない
     * 事業所のご利用者の記録に触れてよい理由はない（要件定義 4.2節）。
     */
    public function update(User $user, ServiceRecord $record): bool
    {
        return $this->sameFacility($user, $record);
    }

    /** ご家族へお渡しする連絡帳の出力。 */
    public function print(User $user, ServiceRecord $record): bool
    {
        return $this->view($user, $record);
    }

    private function sameFacility(User $user, ServiceRecord $record): bool
    {
        $facilityId = $record->resident?->facility_id;

        return $facilityId !== null && $user->facility_id === $facilityId;
    }
}
