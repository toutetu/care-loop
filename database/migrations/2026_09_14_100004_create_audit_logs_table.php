<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 編集履歴（監査ログ）。
 *
 * 【なぜ必要か】
 * ご利用者情報と職員アカウントは、誰がいつ何を変えたのかを後から辿れる
 * 必要がある。要介護度や既往歴の書き換えは、記録の読み方そのものを変える。
 * 職員の役割変更は、誰が何を編集できるかを変える。
 *
 * 変更した本人の記憶に頼る運用は成り立たない。
 *
 * 【変更内容を暗号化する理由】
 * ご利用者の氏名・既往歴・住所は residents 側で暗号化している。
 * その変更前後の値を履歴に平文で積むと、暗号化した意味がなくなる。
 * 履歴のほうが漏えい経路として弱くなっては本末転倒である。
 *
 * 【変更者を残す。削除はしない】
 * user_id は nullOnDelete にしない。職員を削除しても履歴の意味が失われ
 * ないよう、職員アカウント自体を削除しない運用にしている（StaffController）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // 誰が変えたのか。システム実行など操作者がいない場合は null
            $table->foreignId('user_id')->nullable()->comment('変更した職員')
                ->constrained('users')->nullOnDelete();

            // 何を変えたのか（ポリモーフィック：residents / users など）
            $table->string('auditable_type');
            $table->unsignedBigInteger('auditable_id');

            $table->string('event', 20)->comment('created / updated');

            // 変更前後の値。{列名: {before: 値, after: 値}} の形で持つ。
            // 個人情報を含むため暗号化して保存する（モデル側の encrypted:array）
            $table->text('changes')->nullable()->comment('暗号化：変更前後の値');

            // 事後の調査で「どこから操作されたか」を辿るために残す
            $table->string('ip_address', 45)->nullable();

            $table->timestamps();

            // 「この利用者の履歴を新しい順に」が主な引き方
            $table->index(['auditable_type', 'auditable_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
