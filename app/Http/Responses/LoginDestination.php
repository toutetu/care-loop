<?php

namespace App\Http\Responses;

use Illuminate\Http\Request;

/**
 * ログインしたあとに開く場所。
 *
 * 【入口のページへは戻さない】
 * Laravel は、ログイン前に開こうとしていたページへ戻す。ブックマークや
 * ホーム画面のアイコンがダッシュボードを指していると、スマートフォンでも
 * ダッシュボードが開いてしまい、端末に合わせて選ぶ処理（StartController）を
 * 通らない。トップ・ダッシュボードのような「入口」を開こうとしていたときは、
 * 端末に合わせた最初の画面へ送る。
 *
 * 連絡の部屋のように、特定の画面を開こうとしていたときはそこへ戻す。
 * 通知から開いた連絡が、ログインを挟むと記録一覧になってしまっては困る。
 *
 * パスキーでのログインは、何もしなければトップページへ戻る
 * （config('passkeys.redirect') の既定値が "/"）。これも同じ扱いにする。
 */
class LoginDestination
{
    /** 端末に合わせた最初の画面へ置き換える入口。 */
    private const ENTRANCES = ['/', '/dashboard', '/start'];

    public static function url(Request $request): string
    {
        $intended = $request->session()->pull('url.intended');

        if (is_string($intended) && ! in_array(self::pathOf($intended), self::ENTRANCES, true)) {
            return $intended;
        }

        return route('start');
    }

    private static function pathOf(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? '/'.trim($path, '/') : '/';
    }
}
