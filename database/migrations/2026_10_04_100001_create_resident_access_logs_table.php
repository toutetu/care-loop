<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 閲覧履歴（要件定義 F-19、9.3.2節 施策5）。
 *
 * 【なぜ必要か】
 * 情報が漏れたとき、まず問われるのは「誰の情報が見られたか」である。
 * 暗号化や権限は漏れにくくする仕組みで、漏れたあとの範囲は教えてくれない。
 * 内部の不正の抑止にもなる。
 *
 * 【audit_logs と分ける理由】
 * 見ただけでは何も変わらない。変更前後の値を持つ編集履歴とは中身が違い、
 * 行の数も桁違いに多くなる。同じ表に混ぜると、編集履歴が埋もれる。
 *
 * 【ご利用者を1人ずつ開く画面だけを残す】
 * 一覧は開くたびに全員分の行ができ、誰を詳しく見たのかが埋もれる
 * （RecordResidentAccess）。
 *
 * 【書き換えない】
 * updated_at は持たない。後から直せる閲覧履歴は、調査の役に立たない。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resident_access_logs', function (Blueprint $table) {
            $table->id();

            // 職員アカウントは削除しない運用（StaffController）。それでも消えた
            // 場合に履歴ごと失わないよう、行は残して見た人だけを空にする。
            $table->foreignId('user_id')->nullable()->comment('見た職員')
                ->constrained('users')->nullOnDelete();

            // ご利用者の記録を保存期間の満了で消すときは、閲覧履歴も一緒に消す。
            // 見られた本人の情報がもう無いのに、所在を示す行だけを残す理由はない。
            $table->foreignId('resident_id')->comment('見られたご利用者')
                ->constrained()->cascadeOnDelete();

            $table->string('action', 30)
                ->comment('どの画面か：view_resident / edit_resident / view_record / print_family_report');

            // 事後の調査で「どこから見られたか」を辿るために残す
            $table->string('ip_address', 45)->nullable();

            $table->timestamp('created_at')->useCurrent();

            // 「このご利用者の情報を、誰がいつ見たか」と
            // 「この職員が、誰の情報をいつ見たか」の両方から引く
            $table->index(['resident_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resident_access_logs');
    }
};
