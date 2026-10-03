<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * ログインした直後に開く画面を、使っている端末に合わせて選ぶ。
 *
 * 【端末の判定は画面幅で行う】
 * ブラウザが名乗る端末名（User-Agent）は使わない。iPad は Mac と名乗るので
 * 見分けられない。画面側で幅を測って device という Cookie に書き、
 * ここではそれを読むだけにする（resources/js/lib/device-class.ts）。
 *
 * 【スマートフォンとタブレットは記録から始める】
 * スマートフォンを開くのは、介助の合間に気づいたことを残すときである。
 * タブレットはフロアに置き、食事や入浴をまとめて入れるときに開く。
 * どちらもダッシュボードを経由させると、そのぶん1回多く押させることになる。
 * 記録の一覧には、タブレットのときだけ一括入力への大きな入口を出す。
 * 朝礼で全体を見るダッシュボードは、PCで開く画面として残す。
 */
class StartController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        return match ($request->cookie('device')) {
            'phone', 'tablet' => to_route('records.index'),
            default => to_route('dashboard'),
        };
    }
}
