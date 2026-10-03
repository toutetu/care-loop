<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * 職員どうしのメッセージ。
 *
 * 【消さない】
 * 削除の手段は用意しない。トラブルのあとで都合の悪い発言を消せるなら、
 * 業務の記録として残す意味がない。直したいときは editBody() で直し、
 * 直す前の文は revisions に残る。
 *
 * 【本文は暗号化する】
 * ご利用者の名前や体調が書かれる。residents で暗号化している氏名が、
 * ここに平文で残っては意味がない。
 *
 * 【AIへは送らない】
 * 職員どうしのやり取りは、記録の文章を整える材料にしない。LLM を呼ぶ処理
 * （app/Llm）からは参照しない。
 *
 * @property int $id
 * @property int $message_room_id
 * @property int|null $user_id
 * @property string $body
 * @property CarbonInterface|null $edited_at
 * @property CarbonInterface $created_at
 */
#[Fillable(['message_room_id', 'user_id', 'body'])]
class Message extends Model
{
    protected function casts(): array
    {
        return [
            'body' => 'encrypted',
            'edited_at' => 'datetime',
        ];
    }

    /**
     * 本文を直す。直す前の文は消さずに残す。
     *
     * 同じ文のまま保存し直しただけなら何もしない。履歴が積まれると、
     * 本当に直した回数が分からなくなる。
     */
    public function editBody(string $body): void
    {
        if ($body === $this->body) {
            return;
        }

        DB::transaction(function () use ($body): void {
            $this->revisions()->create(['body' => $this->body]);

            $this->forceFill([
                'body' => $body,
                'edited_at' => now(),
            ])->save();
        });
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
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * 編集する前の本文。古い順。
     *
     * @return HasMany<MessageRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(MessageRevision::class)->oldest('id');
    }
}
