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

    /** 記録の編集。職員は自分が記録したものだけ、管理者以上は制限なし。 */
    public function update(User $user, ServiceRecord $record): bool
    {
        if (! $this->sameFacility($user, $record)) {
            return false;
        }

        return $user->role->canEditOthersRecords() || $record->recorded_by === $user->id;
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
