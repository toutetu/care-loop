<?php

namespace Database\Factories;

use App\Models\Resident;
use App\Models\ServiceRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ServiceRecord> */
class ServiceRecordFactory extends Factory
{
    protected $model = ServiceRecord::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'resident_id' => Resident::factory(),
            'recorded_by' => User::factory(),
            'service_date' => fake()->dateTimeBetween('-3 months', 'now')->format('Y-m-d'),
            'arrival_time' => '09:30',
            'departure_time' => '16:15',
            'attendance_status' => 'attended',
            'total_water_ml' => fake()->numberBetween(850, 1400),
            'raw_note' => null,
            'record_text' => fake()->randomElement([
                '入浴は手すりを使用し、一部介助で実施。レクリエーションに参加され、表情は穏やかであった。',
                '午前中は機能訓練に参加。立ち上がり動作は自力で可能であった。',
                '昼食後に傾眠が見られたため、居室にて休憩していただいた。',
            ]),
            'confirmed_at' => now(),
        ];
    }

    /** 欠席された日。 */
    public function absent(): static
    {
        return $this->state(fn (): array => [
            'attendance_status' => 'absent',
            'absence_reason' => fake()->randomElement(['発熱のため', 'ご家族の都合', '受診のため']),
            'arrival_time' => null,
            'departure_time' => null,
            'total_water_ml' => null,
            'record_text' => null,
        ]);
    }

    /** 音声入力された直後で、AI整形がまだの記録。 */
    public function rawOnly(): static
    {
        return $this->state(fn (): array => [
            'raw_note' => 'えーっと今日は入浴のとき浴槽またぐの右足あがり悪くて腰支えた あと昼ごはん半分くらい',
            'record_text' => null,
            'family_text' => null,
            'handover_note' => null,
            'confirmed_at' => null,
        ]);
    }
}
