<?php

namespace App\Models;

use App\Enums\MessageRoomKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 職員どうしの連絡の部屋。
 *
 * 施設全員の部屋は事業所ごとに1つだけ作り、同じ事業所の職員なら誰でも
 * 参加者になる。グループと個別は、message_room_members に行がある職員だけが
 * 参加者になる。
 *
 * @property int $id
 * @property int $facility_id
 * @property MessageRoomKind $kind
 * @property string|null $name
 * @property string|null $direct_key
 * @property int|null $created_by
 */
#[Fillable(['facility_id', 'kind', 'name', 'direct_key', 'created_by'])]
class MessageRoom extends Model
{
    protected function casts(): array
    {
        return [
            'kind' => MessageRoomKind::class,
        ];
    }

    /**
     * 事業所の「施設全員」の部屋。なければ作る。
     *
     * 職員が最初に連絡を開いたときに作る。事業所を登録する処理に混ぜると、
     * 連絡を使わない環境にも部屋ができる。
     */
    public static function forFacility(int $facilityId): self
    {
        return self::query()->firstOrCreate([
            'facility_id' => $facilityId,
            'kind' => MessageRoomKind::Facility,
        ]);
    }

    /**
     * 2人の個別の部屋。なければ作る。
     *
     * 同じ相手との部屋が増えると、どこで何を話したのかが分からなくなる。
     * どちらが先に開いても同じ部屋になるよう、IDの小さい順に並べた鍵で探す。
     */
    public static function directBetween(User $a, User $b): self
    {
        $ids = [$a->id, $b->id];
        sort($ids);

        $room = self::query()->firstOrCreate(
            ['facility_id' => $a->facility_id, 'direct_key' => implode(':', $ids)],
            ['kind' => MessageRoomKind::Direct, 'created_by' => $a->id],
        );

        $room->members()->syncWithoutDetaching($ids);

        return $room;
    }

    public function isFacilityWide(): bool
    {
        return $this->kind === MessageRoomKind::Facility;
    }

    /** この職員が参加者か。施設全員の部屋は同じ事業所なら誰でも参加者。 */
    public function hasParticipant(User $user): bool
    {
        if ($user->facility_id !== $this->facility_id) {
            return false;
        }

        if ($this->isFacilityWide()) {
            return true;
        }

        return $this->members()->whereKey($user->id)->exists();
    }

    /**
     * @return BelongsTo<Facility, $this>
     */
    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    /**
     * グループと個別の参加者。施設全員の部屋では「どこまで読んだか」を
     * 残すためだけに行を作る。
     *
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'message_room_members')
            ->withPivot('last_read_message_id')
            ->withTimestamps();
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * @return HasMany<MessageAccessLog, $this>
     */
    public function accessLogs(): HasMany
    {
        return $this->hasMany(MessageAccessLog::class);
    }
}
