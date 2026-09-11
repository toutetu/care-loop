<?php

namespace App\Models;

use Database\Factories\VitalSignFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * バイタル。1日に複数回測定するため記録から分離している（第1正規形）。
 *
 * 値はすべて nullable。未測定を 0 で埋めない。
 * 測っていないことと、ゼロだったことは違う。
 *
 * @property int $id
 * @property int $service_record_id
 * @property Carbon $measured_at
 * @property string|null $timing
 * @property float|null $temperature
 * @property int|null $systolic_bp
 * @property int|null $diastolic_bp
 * @property int|null $pulse
 * @property int|null $spo2
 */
#[Fillable([
    'service_record_id', 'measured_at', 'timing',
    'temperature', 'systolic_bp', 'diastolic_bp', 'pulse', 'spo2',
])]
class VitalSign extends Model
{
    /** @use HasFactory<VitalSignFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'measured_at' => 'datetime',
            'temperature' => 'float',
        ];
    }

    /**
     * しきい値を外れた項目を返す。判定はすべて設定値に基づく決定的なロジックで、
     * LLMは使わない（要件定義 7.1節）。
     *
     * @return array<int, string> 逸脱した項目のキー
     */
    public function abnormalItems(): array
    {
        $t = config('careloop.risk_thresholds');
        $abnormal = [];

        if ($this->temperature !== null && $this->temperature >= $t['temperature_high']) {
            $abnormal[] = 'temperature';
        }

        if ($this->systolic_bp !== null
            && ($this->systolic_bp >= $t['systolic_bp_high'] || $this->systolic_bp <= $t['systolic_bp_low'])) {
            $abnormal[] = 'systolic_bp';
        }

        if ($this->spo2 !== null && $this->spo2 <= $t['spo2_low']) {
            $abnormal[] = 'spo2';
        }

        return $abnormal;
    }

    public function hasAbnormality(): bool
    {
        return $this->abnormalItems() !== [];
    }

    /**
     * @return BelongsTo<ServiceRecord, $this>
     */
    public function serviceRecord(): BelongsTo
    {
        return $this->belongsTo(ServiceRecord::class);
    }
}
