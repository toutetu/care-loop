<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 編集する前のメッセージ本文。書き換えない。
 *
 * created_at は「この文が置き換えられた日時」にあたる。
 *
 * @property int $id
 * @property int $message_id
 * @property string $body
 * @property CarbonInterface $created_at
 */
#[Fillable(['message_id', 'body'])]
class MessageRevision extends Model
{
    protected function casts(): array
    {
        return [
            'body' => 'encrypted',
        ];
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
