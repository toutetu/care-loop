<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\BatchEntryController;
use App\Http\Controllers\DailyFamilyReportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LlmActionController;
use App\Http\Controllers\LlmJobController;
use App\Http\Controllers\LlmLogController;
use App\Http\Controllers\ResidentController;
use App\Http\Controllers\ServiceRecordController;
use App\Http\Controllers\StaffController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    /*
     * --- ご利用者 ---
     *
     * 登録と編集は生活相談員以上に限る（ResidentPolicy）。
     * 新規のご利用者を迎えるのは契約の手続きであり、フロアの職員が
     * 登録できる必要はない。
     *
     * create / edit を {resident} より先に置く。あとに置くと
     * residents/create が「create という ID のご利用者」として解釈される。
     */
    Route::get('residents', [ResidentController::class, 'index'])->name('residents.index');
    Route::get('residents/create', [ResidentController::class, 'create'])->name('residents.create');
    Route::post('residents', [ResidentController::class, 'store'])->name('residents.store');
    Route::get('residents/{resident}/edit', [ResidentController::class, 'edit'])->name('residents.edit');
    Route::put('residents/{resident}', [ResidentController::class, 'update'])->name('residents.update');
    Route::get('residents/{resident}', [ResidentController::class, 'show'])->name('residents.show');

    /*
     * --- 職員アカウント（管理者のみ） ---
     *
     * 退職者は削除せず、在籍の有無で切り替える。削除すると、その職員が
     * 書いた記録の記録者が辿れなくなる。
     */
    Route::get('staff', [StaffController::class, 'index'])->name('staff.index');
    Route::get('staff/create', [StaffController::class, 'create'])->name('staff.create');
    Route::post('staff', [StaffController::class, 'store'])->name('staff.store');
    Route::get('staff/{user}/edit', [StaffController::class, 'edit'])->name('staff.edit');
    Route::put('staff/{user}', [StaffController::class, 'update'])->name('staff.update');

    /*
     * --- 一括入力（入浴・食事・バイタル） ---
     *
     * 記録入力画面と同じ表へ書く。入浴介助を終えた職員が、担当した方を
     * 順に入れる場面のための画面で、ご利用者を1人ずつ開き直さずに済む。
     *
     * {kind} はコントローラ側で入浴・食事・バイタルのいずれかに限っている。
     */
    Route::get('records/batch/{kind}', [BatchEntryController::class, 'index'])
        ->name('records.batch');
    Route::post('records/batch/{kind}', [BatchEntryController::class, 'store'])
        ->name('records.batch.store');

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

    /*
     * --- 編集履歴（管理者のみ） ---
     *
     * 履歴には変更前の値が含まれる。ご利用者の旧住所や旧連絡先まで見えるため、
     * 日々の介護業務で開く必要はない。
     *
     * 書き換える経路は用意しない。後から都合よく直せる履歴は監査の役に立たない。
     */
    Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');

    // --- AI利用ログ（管理者のみ） ---
    Route::get('llm-logs', [LlmLogController::class, 'index'])->name('llm-logs.index');
});

require __DIR__.'/settings.php';
