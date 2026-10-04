<?php

use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\BatchEntryController;
use App\Http\Controllers\DailyFamilyReportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LlmActionController;
use App\Http\Controllers\LlmJobController;
use App\Http\Controllers\LlmLogController;
use App\Http\Controllers\MessageConsentController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\MessagePostController;
use App\Http\Controllers\MessageRoomController;
use App\Http\Controllers\NoticeController;
use App\Http\Controllers\RecordNoteController;
use App\Http\Controllers\ResidentController;
use App\Http\Controllers\RiskReviewController;
use App\Http\Controllers\ServiceRecordController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\StartController;
use App\Http\Middleware\EnsureMessagingConsent;
use App\Http\Middleware\RecordResidentAccess;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    // ログイン直後の行き先。端末に合わせて記録かダッシュボードへ送る（StartController）
    Route::get('start', StartController::class)->name('start');

    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    /*
     * --- お知らせと連絡 ---
     *
     * スマートフォンの下のバーから開く。お知らせはダッシュボードと同じ抽出条件で、
     * すぐ読めるものだけを並べる。
     */
    Route::get('notices', [NoticeController::class, 'index'])->name('notices.index');

    // 管理者からの周知。開いただけでは確認にせず、職員が押したときだけ記録する
    Route::get('announcements', [AnnouncementController::class, 'index'])->name('announcements.index');
    Route::post('announcements', [AnnouncementController::class, 'store'])->name('announcements.store');
    Route::post('announcements/{announcement}/confirm', [AnnouncementController::class, 'confirm'])
        ->whereNumber('announcement')->name('announcements.confirm');

    /*
     * 連絡（職員どうしのメッセージ）。
     *
     * 使い始める前に承諾を取る（EnsureMessagingConsent）。承諾の画面だけは
     * その外に置く。中に置くと、承諾の画面へ送り返す処理が自分自身に向かう。
     *
     * consent / groups / direct を {messageRoom} より先に置く。あとに置くと
     * 「consent という ID の部屋」として解釈される。
     */
    Route::get('messages/consent', [MessageConsentController::class, 'show'])->name('messages.consent');
    Route::post('messages/consent', [MessageConsentController::class, 'store'])->name('messages.consent.store');

    Route::middleware(EnsureMessagingConsent::class)->group(function () {
        Route::get('messages', [MessageController::class, 'index'])->name('messages.index');
        Route::post('messages/groups', [MessageRoomController::class, 'storeGroup'])->name('messages.groups.store');
        Route::post('messages/direct', [MessageRoomController::class, 'direct'])->name('messages.direct');
        Route::get('messages/{messageRoom}', [MessageRoomController::class, 'show'])
            ->whereNumber('messageRoom')->name('messages.show');
        // 連打や貼り付けの暴走で部屋が埋まらないよう、送信だけ回数を絞る
        Route::post('messages/{messageRoom}', [MessagePostController::class, 'store'])
            ->whereNumber('messageRoom')->middleware('throttle:30,1')->name('messages.store');
        Route::put('messages/{messageRoom}/{message}', [MessagePostController::class, 'update'])
            ->whereNumber(['messageRoom', 'message'])->name('messages.update');
    });

    /*
     * --- ご利用者 ---
     *
     * 登録と編集は生活相談員以上に限る（ResidentPolicy）。
     * 新規のご利用者を迎えるのは契約の手続きであり、フロアの職員が
     * 登録できる必要はない。
     *
     * create / edit を {resident} より先に置く。あとに置くと
     * residents/create が「create という ID のご利用者」として解釈される。
     *
     * ご利用者を1人ずつ開く画面には、閲覧履歴を残す（RecordResidentAccess）。
     * 一覧は残さない。開くたびに全員分の行ができ、誰を詳しく見たのかが埋もれる。
     */
    Route::get('residents', [ResidentController::class, 'index'])->name('residents.index');
    Route::get('residents/create', [ResidentController::class, 'create'])->name('residents.create');
    Route::post('residents', [ResidentController::class, 'store'])->name('residents.store');
    Route::get('residents/{resident}/edit', [ResidentController::class, 'edit'])
        ->middleware(RecordResidentAccess::class.':edit_resident')->name('residents.edit');
    Route::put('residents/{resident}', [ResidentController::class, 'update'])->name('residents.update');
    Route::get('residents/{resident}', [ResidentController::class, 'show'])
        ->middleware(RecordResidentAccess::class.':view_resident')->name('residents.show');

    // リスク兆候の抽出結果を、根拠を読んだうえで「確認済み」にする（RiskReviewController）
    Route::post('risk-assessments/{riskAssessment}/review', [RiskReviewController::class, 'store'])
        ->whereNumber('riskAssessment')->name('risk-assessments.review');

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
    Route::get('records/{serviceRecord}/edit', [ServiceRecordController::class, 'edit'])
        ->middleware(RecordResidentAccess::class.':view_record')->name('records.edit');
    Route::put('records/{serviceRecord}', [ServiceRecordController::class, 'update'])->name('records.update');

    // 音声入力の原文を1件確定する。AIの書き直しとは別の操作にしてある
    // （RecordNoteController）。
    Route::post('records/{serviceRecord}/notes', [RecordNoteController::class, 'store'])
        ->name('records.notes.store');

    // 日次の連絡帳（F-16）。送迎時にお渡しする1枚。
    Route::get('records/{serviceRecord}/family-report', [DailyFamilyReportController::class, 'show'])
        ->middleware(RecordResidentAccess::class.':print_family_report')->name('records.family-report');

    /*
     * AI処理の中止（押し間違いの取り消し）。
     *
     * 下の実行と違って費用を増やす操作ではないので、流量の制限はかけない。
     * 制限にかかって中止できないと、止めたかった書き直しがそのまま
     * 記録に反映される。
     */
    Route::post('llm-jobs/{llmJob}/cancel', [LlmActionController::class, 'cancel'])
        ->name('llm.cancel');

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
     * AI処理の実行状況（生活相談員以上）。
     * 押した処理が通ったのか失敗したのかを確認する場所であり、
     * 費用とトークン数を扱う AI利用ログ（管理者のみ）とは役割が違う。
     *
     * 一覧には事業所ぜんぶの実行が並ぶので、介護職員には開かせない。
     * 自分が押した音声整形の結果は、その記録の編集画面に出る。
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
