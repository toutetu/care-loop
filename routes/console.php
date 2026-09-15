<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * デモの1日ぶんを毎朝つくる。
 *
 * 【なぜ登録を条件つきにするか】
 * careloop:demo-day は架空の記録を書き足すコマンドで、本物の事業所の
 * データベースへ向けて動いてはいけない。コマンド自身も同じ値を見て断るが、
 * 登録の段階でも外しておく。毎朝エラーで終わる予定が残ると、本当に
 * 直すべき失敗がその中に埋もれる。
 *
 * 【9時である理由】
 * 通所介護は9時台に送迎車が着く。到着してバイタルを測り終えた状態を、
 * 実際にそうなっている時刻に作る。時刻は Asia/Tokyo（config/app.php）。
 *
 * 【実行にはスケジューラが要る】
 * この登録だけでは動かない。1分ごとに schedule:run を呼ぶ仕組み
 * （Laravel Cloud の Scheduler）を有効にする必要がある。
 * 手順は docs/02_デプロイ手順.md に書いてある。
 */
if (config('careloop.is_demo')) {
    Schedule::command('careloop:demo-day')
        ->dailyAt('09:00')
        ->withoutOverlapping()
        ->runInBackground();
}
