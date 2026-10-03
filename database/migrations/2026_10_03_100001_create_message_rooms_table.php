<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 職員どうしの連絡の「部屋」。施設全員・グループ・個別の3種類。
 *
 * 【私物の連絡アプリを使わせないため】
 * 現場の連絡が個人の LINE に流れると、ご利用者の様子が業務の外に残り、
 * 言った言わないの行き違いも後から確かめられない。業務の連絡は事業所の
 * 記録として残し、管理者が後から確認できる場所に置く。
 *
 * 個別の部屋は2人の組み合わせで1つにする（direct_key）。同じ相手との
 * 部屋が増えると、どこで何を話したのかが分からなくなる。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facility_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10)->comment('facility / group / direct');
            $table->string('name', 50)->nullable()->comment('グループの名前。施設全員と個別は画面側で決める');
            $table->string('direct_key', 40)->nullable()
                ->comment('個別の部屋の2人。小さいほうのID:大きいほうのID');
            $table->foreignId('created_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['facility_id', 'kind']);
            $table->unique(['facility_id', 'direct_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_rooms');
    }
};
