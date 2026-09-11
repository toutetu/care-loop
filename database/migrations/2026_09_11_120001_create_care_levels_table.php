<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 要介護度マスタ。
 *
 * 【第3正規形】
 * residents に care_level_name（「要介護3」）を直接持たせると、その名称は
 * care_level_code に従属し、residents の主キーには推移的にしか従属しない
 * （推移的関数従属）。名称変更時に全利用者の更新が必要になるため、
 * マスタへ分離して residents.care_level_id から参照する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('care_levels', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique()->comment('support1 / care1 など');
            $table->string('name', 20)->comment('要支援1 / 要介護1 など');
            $table->unsignedTinyInteger('sort_order')->comment('軽度から重度への並び順');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('care_levels');
    }
};
