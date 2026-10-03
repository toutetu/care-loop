<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 連絡を使い始めるときの承諾。文面の版ごとに残す（MessagingTerms）。
 *
 * @property int $id
 * @property int $user_id
 * @property string $version
 * @property CarbonInterface $agreed_at
 */
#[Fillable(['user_id', 'version', 'agreed_at'])]
class MessageConsent extends Model
{
    protected function casts(): array
    {
        return [
            'agreed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
