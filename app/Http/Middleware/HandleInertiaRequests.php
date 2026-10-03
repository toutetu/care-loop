<?php

namespace App\Http\Middleware;

use App\Support\MessageInbox;
use App\Support\NoticeBoard;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            /*
             * 下のバーの「お知らせ」に出す件数。
             *
             * 数字が付いていなければ、職員は開かない。どの画面にいても目に入るよう、
             * 画面ごとではなく共通の値として渡す。関数で包んで、使う画面を開いた
             * ときだけ数える。
             */
            'noticeCount' => fn () => $request->user()
                ? NoticeBoard::countFor($request->user())
                : 0,
            // 下のバーの「連絡」に出す未読の数。参加しているすべての部屋の合計。
            'messageUnread' => fn () => $request->user()
                ? (new MessageInbox($request->user()))->unreadTotal()
                : 0,
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            /*
             * AI機能の実行結果を画面に返すために使う。
             *
             * 成功と失敗を別のキーにしているのは、失敗のときに画面の色と
             * 残り時間を変えるため。LLMの失敗は「もう一度押せばよい」ものと
             * 「管理者に連絡が要る」ものがあり、読み飛ばされては困る。
             */
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
        ];
    }
}
