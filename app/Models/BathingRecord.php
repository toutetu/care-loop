<?php

namespace App\Models;

use App\Enums\BathingType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 入浴・清拭。1日に複数回ありうるため記録から分離している。
 *
 * 以前は service_records の1カラムだった。午前に入浴して午後に清拭する日も、
 * 入浴を担当した職員が自分の名前で残すこともできなかった。
 *
 * bathed_at は不明なら null のままにする。それらしい時刻で埋めると、
 * あとから見たときに実際の時刻なのか埋めた値なのかが区別できなくなる。
 *
 * @property int $id
 * @property int $service_record_id
 * @property int|null $recorded_by
 * @property CarbonInterface|null $bathed_at
 * @property BathingType $bathing_type
 * @property string|null $note
 */
#[Fillable([
    'service_record_id', 'recorded_by', 'bathed_at', 'bathing_type', 'note',
])]
class BathingRecord extends Model
{
    protected function casts(): array
    {
        return [
            'bathed_at' => 'datetime',
            'bathing_type' => BathingType::class,
        ];
    }

    /**
     * @return BelongsTo<ServiceRecord, $this>
     */
    public function serviceRecord(): BelongsTo
    {
        return $this->belongsTo(ServiceRecord::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
