<?php

namespace App\Http\Requests;

use App\Enums\NoteInputMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 音声入力・手入力の原文の確定。
 *
 * 【上限を記録本文とそろえる】
 * 確定した原文は、そのままAIへの材料になる。音声入力を止め忘れて延々と
 * 続いた文を受け入れると、トークンも費用も跳ね上がる。
 */
class StoreRecordNoteRequest extends FormRequest
{
    /** 権限は ServiceRecordPolicy で判定する（コントローラで Gate::authorize）。 */
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
            'body' => ['required', 'string', 'max:5000'],
            // 画面のマイクで入れたときだけ voice が届く。OSのキーボードの
            // 音声入力はアプリから見分けられないため、手入力として残る。
            'input_method' => ['nullable', Rule::enum(NoteInputMethod::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'body' => '原文',
            'input_method' => '入力方法',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.required' => '確定する内容がありません。音声入力か手入力で入れてから「確定」を押してください。',
        ];
    }
}
