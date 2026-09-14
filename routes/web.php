<?php

use App\Http\Controllers\DailyFamilyReportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LlmActionController;
use App\Http\Controllers\LlmJobController;
use App\Http\Controllers\LlmLogController;
use App\Http\Controllers\ResidentController;
use App\Http\Controllers\ServiceRecordController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // --- ご利用者 ---
    Route::get('residents', [ResidentController::class, 'index'])->name('residents.index');
    Route::get('residents/{resident}', [ResidentController::class, 'show'])->name('residents.show');

    // --- サービス提供記録 ---
    Route::get('records', [ServiceRecordController::class, 'index'])->name('records.index');
    Route::get('records/{serviceRecord}/edit', [ServiceRecordController::class, 'edit'])->name('records.edit');
    Route::put('records/{serviceRecord}', [ServiceRecordController::class, 'update'])->name('records.update');

    // 日次の連絡帳（F-16）。送迎時にお渡しする1枚。
    Route::get('records/{serviceRecord}/family-report', [DailyFamilyReportController::class, 'show'])
        ->name('records.family-report');

    /*
     * AI機能の実行。
     *
     * 1回ごとに費用が発生する操作なので、GET では公開しない。
     * リンクを踏んだだけ・ブラウザが先読みしただけで課金される状態を作らない。
     *
     * throttle:llm で流量を絞る（AppServiceProvider）。月次の上限に達してから
     * 止まるのでは、その月のデモ全体が動かなくなるため。
     */
    Route::middleware('throttle:llm')->group(function () {
        Route::post('records/{serviceRecord}/voice-transform', [LlmActionController::class, 'transformVoice'])
            ->name('llm.voice-transform');
        Route::post('residents/{resident}/risk-detection', [LlmActionController::class, 'detectRisks'])
            ->name('llm.risk-detection');
        Route::post('residents/{resident}/goal-progress', [LlmActionController::class, 'goalProgress'])
            ->name('llm.goal-progress');
    });

    /*
     * AI処理の実行状況。職員全員が開ける。
     * 押した処理が通ったのか失敗したのかを確認する場所であり、
     * 費用とトークン数を扱う AI利用ログ（管理者のみ）とは役割が違う。
     */
    Route::get('llm-jobs', [LlmJobController::class, 'index'])->name('llm-jobs.index');

    // --- AI利用ログ（管理者のみ） ---
    Route::get('llm-logs', [LlmLogController::class, 'index'])->name('llm-logs.index');
});

require __DIR__.'/settings.php';
