<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 公開時に閉じる認証機能を遮断する。
 *
 * 【なぜ Fortify の機能を外さないのか】
 * config/fortify.php の features から外せば、ルートごと登録されなくなる。
 * しかしそうすると Wayfinder が resources/js/routes/register.ts を
 * 生成せず、それを import している画面のビルドが失敗する。
 * 環境変数の値でフロントエンドのビルドが壊れる状態は、
 * デプロイのたびに事故を起こす。
 *
 * ルートは常に登録しておき、ここで 404 を返す。
 * 外から見れば「その機能は存在しない」という結果は変わらない。
 *
 * 【403 ではなく 404 を返す】
 * 403 は「機能はあるが、あなたには使わせない」と読める。
 * この環境に登録機能は無い、という事実をそのまま返すほうが正確である。
 */
class EnsureAuthFeatureEnabled
{
    /**
     * 閉じられる機能と、それが持つルート名。
     *
     * @var array<string, list<string>>
     */
    private const GUARDED = [
        'registration' => ['register', 'register.store'],
        'password_reset' => ['password.request', 'password.email', 'password.reset', 'password.update'],
    ];

    public function handle(Request $request, Closure $next): Response
    {
        foreach (self::GUARDED as $feature => $routes) {
            if ($request->routeIs($routes) && ! config("careloop.features.{$feature}")) {
                abort(404);
            }
        }

        return $next($request);
    }
}
