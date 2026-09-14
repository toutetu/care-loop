<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        /*
         * AI実行が流量制限に達したときの表示。
         *
         * 既定では 429 のエラーページが出る。職員には何が起きたのか分からず、
         * 入力していた記録も見えなくなる。元の画面へ戻し、日本語で理由を伝える。
         * LLMの失敗をすべて日本語で返す方針（要件定義 7.4節）に合わせている。
         *
         * 対象をAI実行のルートに限る。ログインの試行回数制限まで 302 に変えると、
         * 総当たりを試みている側に「まだ弾かれていない」と読める応答を返すことになる。
         */
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if ($request->expectsJson() || ! $request->routeIs('llm.*')) {
                return null;
            }

            $seconds = (int) ($e->getHeaders()['Retry-After'] ?? 60);

            return back()->with(
                'error',
                sprintf('AI機能の実行が続いています。%d秒ほどおいてからお試しください。', $seconds),
            );
        });
    })->create();
