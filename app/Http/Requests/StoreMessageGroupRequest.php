<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 連絡のグループを作る。
 *
 * 参加者は同じ事業所の在籍中の職員に限る。他の事業所の職員を混ぜられると、
 * そのグループに書いたご利用者の情報が事業所の外へ出る。
 */
class StoreMessageGroupRequest extends FormRequest
{
    /** 権限は MessageRoomPolicy::createGroup で判定する（コントローラで Gate::authorize）。 */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->user();

        return [
            'name' => ['required', 'string', 'max:50'],
            'member_ids' => ['required', 'array', 'min:1'],
            'member_ids.*' => [
                'integer',
                Rule::exists('users', 'id')
                    ->where('facility_id', $user->facility_id)
                    ->where('is_active', true),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'グループの名前',
            'member_ids' => '参加する職員',
            'member_ids.*' => '参加する職員',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'member_ids.required' => '参加する職員を1人以上選んでください。',
        ];
    }
}
