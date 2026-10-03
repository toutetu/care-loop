<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 管理者からの周知と、職員が「確認しました」を押した記録。
 *
 * 【連絡（messages）と分けた理由】
 * 連絡は流れていくやり取りで、読んだかどうかは「どこまで読んだか」で足りる。
 * 周知は全員に必ず届いたことを確かめたいもので、1人ずつ「確認した」ことを
 * 残す必要がある。施設全員の部屋に流すと、他の連絡に埋もれ、誰が読んだかも
 * 分からない。
 *
 * 【開いただけでは確認にしない】
 * 画面を開いたことを既読にすると、スクロールで通り過ぎただけでも
 * 「周知済み」になる。職員がボタンを押したときだけ記録する。
 *
 * 【書き換えない】
 * 確認したあとで文面が変わると、職員が何に「確認しました」と言ったのかが
 * 分からなくなる。訂正は新しい周知として出す。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facility_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()
                ->comment('周知した管理者')
                ->constrained()->nullOnDelete();
            $table->text('title')->comment('件名（暗号化）');
            $table->text('body')->comment('本文（暗号化）');
            $table->boolean('is_important')->default(false)
                ->comment('重要。お知らせの中で目立たせる');
            $table->timestamps();

            $table->index(['facility_id', 'created_at']);
        });

        Schema::create('announcement_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('announcement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('confirmed_at')->comment('「確認しました」を押した日時');
            $table->timestamps();

            $table->unique(['announcement_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_reads');
        Schema::dropIfExists('announcements');
    }
};
