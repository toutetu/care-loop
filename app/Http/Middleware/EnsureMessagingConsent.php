<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\MessagingTerms;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 連絡の画面は、承諾を済ませるまで開かせない。
 *
 * 画面の上に注意書きを出すだけでは読み飛ばされる。「管理者が後から読める」
 * ことに同意したという記録がないまま書かれた連絡は、後で揉めたときに
 * 根拠として使いにくい（MessagingTerms）。
 */
class EnsureMessagingConsent
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User $user */
        $user = $request->user();

        if (! MessagingTerms::acceptedBy($user)) {
            return to_route('messages.consent');
        }

        return $next($request);
    }
}
