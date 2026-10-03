<?php

namespace App\Policies;

use App\Models\RiskAssessment;
use App\Models\User;

/**
 * リスク兆候の抽出結果を「確認済み」にする権限。
 *
 * 同じ事業所の職員なら誰でもできる。AIの出力を職員が検証するという前提
 * （要件定義 7.3節）は、管理者だけが読むことを想定していない。フロアで
 * ご利用者を見ている職員が根拠を読み、確かめたことを残せるようにする。
 *
 * 誰が確認したかは reviewed_by に残る。確認できる人を狭めるのではなく、
 * 確認した人が分かるようにして責任の所在を保つ。
 */
class RiskAssessmentPolicy
{
    public function review(User $user, RiskAssessment $assessment): bool
    {
        $facilityId = $assessment->resident?->facility_id;

        return $facilityId !== null && $user->facility_id === $facilityId;
    }
}
