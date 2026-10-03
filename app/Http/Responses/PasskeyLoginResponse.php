<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Passkeys\Contracts\PasskeyLoginResponse as PasskeyLoginResponseContract;

/**
 * パスキー（顔認証・指紋）でのログイン。
 *
 * 既定ではトップページへ戻り、ログインしたのに紹介ページが開く。スマートフォンで
 * いちばん使われるログインの方法なので、ほかの方法と同じ行き先にそろえる。
 */
class PasskeyLoginResponse implements PasskeyLoginResponseContract
{
    /**
     * @param  Request  $request
     */
    public function toResponse($request): JsonResponse|RedirectResponse
    {
        $url = LoginDestination::url($request);

        return $request->wantsJson()
            ? new JsonResponse(['redirect' => $url])
            : redirect()->to($url);
    }
}
