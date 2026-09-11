<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 事業所。単独型の通所介護事業所を想定する。
 * 職員・利用者の所属先として持たせ、将来の多事業所対応の余地を残す。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facilities', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('service_type', 30)->default('day_service')->comment('通所介護');
            $table->unsignedSmallInteger('capacity')->nullable()->comment('定員');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facilities');
    }
};
