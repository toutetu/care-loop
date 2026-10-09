<?php

namespace App\Models;

use App\Enums\ResidentAccessAction;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * 閲覧履歴。誰がいつどのご利用者の画面を開いたかを残す。書き換えない。
 *
 * @property int $id
 * @property int|null $user_id
 * @property int $resident_id
 * @property ResidentAccessAction $action
 * @property string|null $ip_address
 * @property CarbonInterface $created_at
 */
#[Fillable(['user_id', 'resident_id', 'action', 'ip_address'])]
class ResidentAccessLog extends Model
{
    /** 後から直せる閲覧履歴は、調査の役に立たない。 */
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'action' => ResidentAccessAction::class,
            'created_at' => 'datetime',
        ];
    }
}
