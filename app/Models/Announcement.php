<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 管理者からの周知。職員が1人ずつ「確認しました」を押す。
 *
 * 【書き換えない・消さない】
 * 確認したあとで文面が変わると、職員が何を確認したのかが分からなくなる。
 * 編集と削除の手段は用意しない。訂正は新しい周知として出す。
 *
 * 【件名と本文は暗号化する】
 * 「佐藤様の食事形態の変更」のように、ご利用者のことが書かれる。
 *
 * @property int $id
 * @property int $facility_id
 * @property int|null $user_id
 * @property string $title
 * @property string $body
 * @property bool $is_important
 * @property CarbonInterface $created_at
 */
#[Fillable(['facility_id', 'user_id', 'title', 'body', 'is_important'])]
class Announcement extends Model
{
    protected function casts(): array
    {
        return [
            'title' => 'encrypted',
            'body' => 'encrypted',
            'is_important' => 'boolean',
        ];
    }

    /**
     * この職員がまだ確認していない周知。
     *
     * 自分が出した周知は含めない。書いた本人に「確認しました」を
     * 押させても意味がない。
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeUnconfirmedBy(Builder $query, User $user): Builder
    {
        return $query
            ->where('facility_id', $user->facility_id)
            ->where(fn ($author) => $author
                ->whereNull('user_id')
                ->orWhere('user_id', '<>', $user->id))
            ->whereDoesntHave('reads', fn ($reads) => $reads->where('user_id', $user->id));
    }

    public function isConfirmedBy(User $user): bool
    {
        return $this->user_id === $user->id
            || $this->reads()->where('user_id', $user->id)->exists();
    }

    /** 「確認しました」を残す。2回押しても1件にする。 */
    public function confirm(User $user): void
    {
        $this->reads()->firstOrCreate(
            ['user_id' => $user->id],
            ['confirmed_at' => now()],
        );
    }

    /**
     * @return BelongsTo<Facility, $this>
     */
    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return HasMany<AnnouncementRead, $this>
     */
    public function reads(): HasMany
    {
        return $this->hasMany(AnnouncementRead::class);
    }
}
