<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 職員どうしのメッセージ。
 *
 * 【消さない】
 * 送ったものを消す手段は用意しない。トラブルのあとで都合の悪い発言を
 * 消せるなら、記録として残す意味がない。直したいときは編集し、直す前の
 * 文は message_revisions に残す。
 *
 * 【本文は暗号化する】
 * 連絡にはご利用者の名前や体調が書かれる。ご利用者の氏名は residents で
 * 暗号化しているので、ここを平文にすると、漏えい経路として弱いほうに
 * なってしまう。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()
                ->comment('送った職員')
                ->constrained()->nullOnDelete();
            $table->text('body')->comment('本文（暗号化）');
            $table->timestamp('edited_at')->nullable()->comment('最後に編集した日時');
            $table->timestamps();

            $table->index(['message_room_id', 'id']);
        });

        Schema::create('message_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->text('body')->comment('編集する前の本文（暗号化）');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_revisions');
        Schema::dropIfExists('messages');
    }
};
