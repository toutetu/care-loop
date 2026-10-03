<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 周知を出す。
 *
 * 件名を必須にする。お知らせの一覧では件名だけを見て開くかどうかを決めるので、
 * 本文の書き出しが件名の代わりになると、何の周知なのかが一目で分からない。
 */
class StoreAnnouncementRequest extends FormRequest
{
    /** 権限は AnnouncementPolicy::create で判定する（コントローラで Gate::authorize）。 */
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
            'title' => ['required', 'string', 'max:100'],
            'body' => ['required', 'string', 'max:3000'],
            'is_important' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'title' => '件名',
            'body' => '本文',
            'is_important' => '重要',
        ];
    }
}
