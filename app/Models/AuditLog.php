<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * 編集履歴。誰がいつ何を変えたのかを残す。
 *
 * 【変更内容を暗号化している】
 * ご利用者の氏名・既往歴・住所は residents 側で暗号化している。
 * その変更前後の値を履歴に平文で積むと、暗号化した意味がなくなる。
 * 履歴のほうが漏えい経路として弱くなっては本末転倒である。
 *
 * 【書き換えない】
 * 履歴そのものを編集する手段は用意しない。後から都合よく直せる履歴は、
 * 監査の役に立たない。
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $auditable_type
 * @property int $auditable_id
 * @property string $event
 * @property array<string, array{before: mixed, after: mixed}>|null $changes
 * @property string|null $ip_address
 * @property CarbonInterface $created_at
 */
#[Fillable(['user_id', 'auditable_type', 'auditable_id', 'event', 'changes', 'ip_address'])]
class AuditLog extends Model
{
    protected function casts(): array
    {
        return [
            'changes' => 'encrypted:array',
        ];
    }

    /**
     * 履歴を1件残す。
     *
     * @param  array<string, array{before: mixed, after: mixed}>  $changes
     */
    public static function record(string $event, Model $subject, array $changes): void
    {
        // 変わっていないのに履歴が積まれると、本当に変わった回数が分からなくなる
        if ($changes === []) {
            return;
        }

        self::query()->create([
            'user_id' => auth()->id(),
            'auditable_type' => $subject->getMorphClass(),
            'auditable_id' => $subject->getKey(),
            'event' => $event,
            'changes' => $changes,
            'ip_address' => request()->ip(),
        ]);
    }

    /**
     * 特定の対象の履歴。
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeFor(Builder $query, Model $subject): Builder
    {
        return $query
            ->where('auditable_type', $subject->getMorphClass())
            ->where('auditable_id', $subject->getKey());
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }
}
