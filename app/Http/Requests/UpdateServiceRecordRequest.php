<?php

namespace App\Http\Requests;

use App\Enums\BathingType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * サービス提供記録の更新。
 *
 * 【上限値を入れている理由】
 * 体温 45℃ や血圧 400 は入力誤りである。保存してしまうと、
 * リスク判定のしきい値に引っかかって誤った警告が出る。
 * 桁を打ち間違えたその場で気づける形にしておく。
 *
 * 【必須項目をほとんど置かない理由】
 * 記録は1日かけて少しずつ埋まる。到着時に体温だけ入れて保存し、
 * 昼に食事を足す、という使い方ができないと現場では使えない。
 * 確定（confirmed_at）の時点でそろっていればよい。
 */
class UpdateServiceRecordRequest extends FormRequest
{
    /** 権限は ServiceRecordPolicy で判定する（routes 側の can ミドルウェア）。 */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'arrival_time' => ['nullable', 'date_format:H:i'],
            'departure_time' => ['nullable', 'date_format:H:i', 'after:arrival_time'],
            'attendance_status' => ['required', 'in:attended,absent,cancelled'],
            'absence_reason' => ['nullable', 'string', 'max:255'],
            // 空文字は「未記録」として扱う。選択を外せる必要がある。
            'bathing_type' => ['nullable', Rule::enum(BathingType::class)],
            'total_water_ml' => ['nullable', 'integer', 'min:0', 'max:5000'],

            'vital.temperature' => ['nullable', 'numeric', 'min:30', 'max:43'],
            'vital.systolic_bp' => ['nullable', 'integer', 'min:50', 'max:260'],
            'vital.diastolic_bp' => ['nullable', 'integer', 'min:30', 'max:180'],
            'vital.pulse' => ['nullable', 'integer', 'min:20', 'max:200'],
            'vital.spo2' => ['nullable', 'integer', 'min:50', 'max:100'],

            'lunch.staple_rate' => ['nullable', 'integer', 'min:0', 'max:100'],
            'lunch.side_rate' => ['nullable', 'integer', 'min:0', 'max:100'],
            'lunch.meal_form' => ['nullable', 'string', 'max:30'],
            'lunch.choking' => ['nullable', 'boolean'],

            'raw_note' => ['nullable', 'string', 'max:5000'],
            'record_text' => ['nullable', 'string', 'max:5000'],
            'family_text' => ['nullable', 'string', 'max:5000'],
            'handover_note' => ['nullable', 'string', 'max:2000'],

            'confirm' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'arrival_time' => '到着時刻',
            'departure_time' => '帰宅時刻',
            'attendance_status' => '利用状況',
            'total_water_ml' => '水分摂取量',
            'bathing_type' => '入浴・清拭',
            'vital.temperature' => '体温',
            'vital.systolic_bp' => '収縮期血圧',
            'vital.diastolic_bp' => '拡張期血圧',
            'vital.pulse' => '脈拍',
            'vital.spo2' => 'SpO2',
            'lunch.staple_rate' => '主食の摂取割合',
            'lunch.side_rate' => '副菜の摂取割合',
            'lunch.meal_form' => '食形態',
            'raw_note' => '音声入力の原文',
            'record_text' => '記録',
            'family_text' => 'ご家族向けの文章',
            'handover_note' => '申し送り',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'departure_time.after' => '帰宅時刻は到着時刻より後の時刻を入力してください。',
            'vital.temperature.min' => '体温の値をご確認ください。30℃未満は入力できません。',
            'vital.temperature.max' => '体温の値をご確認ください。43℃を超える値は入力できません。',
        ];
    }
}
