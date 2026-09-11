<?php

namespace App\Models;

use Database\Factories\WeightRecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 体重記録（月次）。低栄養リスクのルールベース判定に使う。
 *
 * @property int $id
 * @property int $resident_id
 * @property Carbon $measured_on
 * @property float $weight_kg
 * @property int|null $recorded_by
 */
#[Fillable(['resident_id', 'measured_on', 'weight_kg', 'recorded_by'])]
class WeightRecord extends Model
{
    /** @use HasFactory<WeightRecordFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'measured_on' => 'date',
            'weight_kg' => 'float',
        ];
    }

    /**
     * 直前の測定からの減少率を返す。増加した場合は負の値になる。
     * 比較対象がなければ null（＝判定不能。0 を返して「変化なし」と誤解させない）。
     */
    public function lossRateFromPrevious(): ?float
    {
        $previous = static::query()
            ->where('resident_id', $this->resident_id)
            ->where('measured_on', '<', $this->measured_on)
            ->latest('measured_on')
            ->first();

        if ($previous === null || $previous->weight_kg <= 0) {
            return null;
        }

        return ($previous->weight_kg - $this->weight_kg) / $previous->weight_kg;
    }

    /**
     * @return BelongsTo<Resident, $this>
     */
    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
