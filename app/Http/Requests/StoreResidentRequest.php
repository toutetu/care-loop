<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ご利用者の登録・編集。
 *
 * 【必須をお名前とカナだけにしている理由】
 * 契約の場でその日に分かることと、後日そろうことがある。保険者番号や
 * 既往歴がそろうまで登録できないと、その日の記録が残せない。
 * 記録を残せることを優先し、不足は後から埋められるようにする。
 *
 * 【カナを必須にしている理由】
 * 氏名は暗号化して保存するためSQLでは検索できない。カナのブラインド
 * インデックスだけが検索の手がかりになる（要件定義 9.3節）。
 * ここが空だと、その方を後から探せなくなる。
 */
class StoreResidentRequest extends FormRequest
{
    /** 権限は ResidentPolicy で判定する。 */
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
            'name' => ['required', 'string', 'max:100'],
            // 全角カタカナ・長音・空白のみ。ひらがなや漢字が混ざると
            // ブラインドインデックスが一致せず、検索から漏れる。
            'name_kana' => ['required', 'string', 'max:100', 'regex:/\A[ァ-ヶー\s　]+\z/u'],
            'care_level_id' => ['nullable', 'integer', Rule::exists('care_levels', 'id')],
            'birth_date' => ['nullable', 'date', 'before:today', 'after:1900-01-01'],
            'gender' => ['nullable', Rule::in(['male', 'female'])],
            'insurance_number' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'family_contact' => ['nullable', 'string', 'max:100'],
            'medical_history' => ['nullable', 'string', 'max:1000'],
            'care_manager_name' => ['nullable', 'string', 'max:60'],
            'started_at' => ['nullable', 'date'],
            'ended_at' => ['nullable', 'date', 'after_or_equal:started_at'],
            'service_weekdays' => ['nullable', 'array'],
            // ISO-8601（月曜=1）で持つ。Carbon の dayOfWeekIso と合わせる
            'service_weekdays.*' => ['integer', 'between:1,7'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'お名前',
            'name_kana' => 'お名前（カナ）',
            'care_level_id' => '要介護度',
            'birth_date' => '生年月日',
            'gender' => '性別',
            'insurance_number' => '被保険者番号',
            'address' => 'ご住所',
            'phone' => '電話番号',
            'family_contact' => 'ご家族の連絡先',
            'medical_history' => '既往歴',
            'care_manager_name' => '担当の介護支援専門員',
            'started_at' => '利用開始日',
            'ended_at' => '利用終了日',
            'service_weekdays' => '利用曜日',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name_kana.regex' => 'お名前（カナ）は全角カタカナで入力してください。'
                .'検索の手がかりになるため、ひらがなや漢字は使えません。',
            'birth_date.before' => '生年月日には本日より前の日付を入力してください。',
            'ended_at.after_or_equal' => '利用終了日は利用開始日と同じか、それより後にしてください。',
        ];
    }
}
