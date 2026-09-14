<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * 職員アカウントの登録・変更。
 *
 * 【パスワードは管理者が決めない】
 * 初期パスワードを管理者が決めて口頭で伝える運用は、そのまま使い続けられる。
 * 本来は招待メールから本人が設定するのが正しい。
 *
 * ただしこのアプリにはメール送信の手配がない。ここでは管理者が設定し、
 * 本人が設定画面から変更する前提とする。実運用へ移す際に招待方式へ
 * 差し替える必要がある点は、READMEに残す。
 */
class StoreUserRequest extends FormRequest
{
    /** 権限は UserPolicy で判定する。 */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // 変更のときだけルートに職員が入る。新規登録では null。
        $user = $this->route('user');
        $userId = $user instanceof User ? $user->getKey() : null;

        return [
            'name' => ['required', 'string', 'max:60'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                // 同じメールアドレスで2つのアカウントを作れると、
                // 記録の書き手を追えなくなる
                Rule::unique('users', 'email')->ignore($userId),
            ],
            'role' => ['required', Rule::enum(UserRole::class)],
            'is_active' => ['nullable', 'boolean'],
            // 新規登録では必須、変更では省略可（空なら据え置き）
            'password' => [
                $userId === null ? 'required' : 'nullable',
                'confirmed',
                Password::defaults(),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'お名前',
            'email' => 'メールアドレス',
            'role' => '役割',
            'password' => 'パスワード',
            'is_active' => '在籍中',
        ];
    }
}
