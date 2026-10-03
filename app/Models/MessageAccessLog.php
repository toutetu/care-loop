<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 管理者が、参加していない部屋を読んだ記録。書き換えない。
 *
 * 読めるだけで記録が残らないと、職員は何がいつ見られたのか分からず不安になる。
 * この記録は、その部屋の参加者にも見せる。
 *
 * @property int $id
 * @property int $message_room_id
 * @property int|null $user_id
 * @property CarbonInterface $viewed_at
 */
#[Fillable(['message_room_id', 'user_id', 'viewed_at'])]
class MessageAccessLog extends Model
{
    protected function casts(): array
    {
        return [
            'viewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<MessageRoom, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(MessageRoom::class, 'message_room_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function viewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
