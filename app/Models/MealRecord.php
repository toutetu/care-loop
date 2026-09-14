<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\MealRecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 食事記録。1日に複数件ありうる（昼食・おやつ、食後の入れ直し）。
 *
 * 以前は (service_record_id, meal_type) で一意にしていたが、あとから
 * 入力した職員が前の1件を上書きすることになっていたため制限を外した。
 * 量が変わったときは書き直すのではなく、その時点の事実として積む。
 *
 * サロゲートキー id を主キーとし、複合キーにしないことで、
 * service_date のような service_record_id のみに従属する項目を
 * このテーブルに持ち込む誘惑（部分関数従属）を構造的に断っている。
 *
 * @property int $id
 * @property int $service_record_id
 * @property int|null $recorded_by
 * @property CarbonInterface|null $recorded_at
 * @property string $meal_type
 * @property int|null $staple_rate
 * @property int|null $side_rate
 * @property string|null $meal_form
 * @property bool $choking
 * @property string|null $note
 */
#[Fillable([
    'service_record_id', 'recorded_by', 'recorded_at',
    'meal_type', 'staple_rate', 'side_rate',
    'meal_form', 'choking', 'note',
])]
class MealRecord extends Model
{
    /** @use HasFactory<MealRecordFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'choking' => 'boolean',
            'recorded_at' => 'datetime',
        ];
    }

    /**
     * 摂取率が低い食事か。低栄養リスクの判定に使う。
     * 主食・副菜のいずれかが未入力の場合は判定しない（推測で埋めない）。
     */
    public function isLowIntake(): bool
    {
        $threshold = (int) config('careloop.risk_thresholds.meal_rate_low');

        return ($this->staple_rate !== null && $this->staple_rate < $threshold)
            || ($this->side_rate !== null && $this->side_rate < $threshold);
    }

    /**
     * @return BelongsTo<ServiceRecord, $this>
     */
    public function serviceRecord(): BelongsTo
    {
        return $this->belongsTo(ServiceRecord::class);
    }

    /**
     * この記録を入力した職員。
     *
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
