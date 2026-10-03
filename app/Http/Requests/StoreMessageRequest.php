<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * メッセージの送信と編集。
 *
 * 【長さに上限を設ける】
 * 連絡は短く伝えるものなので、記録の本文より小さくする。長い報告は
 * 記録に書き、ここでは「記録に書きました」と伝えれば足りる。
 */
class StoreMessageRequest extends FormRequest
{
    /** 権限は MessageRoomPolicy・MessagePolicy で判定する（コントローラで Gate::authorize）。 */
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
            'body' => ['required', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'body' => 'メッセージ',
        ];
    }
}
