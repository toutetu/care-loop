<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 部屋の参加者と、どこまで読んだか。
 *
 * グループと個別は、ここに行がある職員だけが参加者になる。施設全員の部屋は
 * 同じ事業所の職員なら誰でも参加者なので、行は「どこまで読んだか」を
 * 残すためだけに、開いたときに作る。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_room_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('last_read_message_id')->nullable()
                ->comment('最後に読んだメッセージ。未読の数はこれより後の件数');
            $table->timestamps();

            $table->unique(['message_room_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_room_members');
    }
};
