<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 連絡を使い始めるときの承諾と、管理者が部屋を読んだ記録。
 *
 * 【承諾は文面の版ごとに残す】
 * 「管理者が後から読める」ことを知らずに書いた、と言われないようにする。
 * 文面を変えたら版を上げ、もう一度承諾してもらう。どの文面に同意したのかが
 * 後から分かるよう、版と日時を残す。
 *
 * 【管理者が読んだことも残す】
 * 管理者は参加していない部屋も読める。読めるだけでは職員が不安になるので、
 * 誰がいつ読んだかを残し、その部屋の参加者にも見せる。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('version', 20)->comment('承諾した文面の版');
            $table->timestamp('agreed_at');
            $table->timestamps();

            $table->unique(['user_id', 'version']);
        });

        Schema::create('message_access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()
                ->comment('参加者ではないのに読んだ管理者')
                ->constrained()->nullOnDelete();
            $table->timestamp('viewed_at');
            $table->timestamps();

            $table->index(['message_room_id', 'viewed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_access_logs');
        Schema::dropIfExists('message_consents');
    }
};
