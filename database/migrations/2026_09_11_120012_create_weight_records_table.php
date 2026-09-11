<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 体重記録（月次）。
 *
 * リスク判定のルールベース指標に使う。
 *   (前月体重 − 当月体重) ÷ 前月体重 >= 3% → 低栄養リスク
 *
 * この計算はLLMではなく決定的なロジックで行う。数値で判定できるものをLLMに任せると
 * 再現性が失われ、検証もできなくなるため（要件定義 7.1節）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weight_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resident_id')->constrained()->cascadeOnDelete();
            $table->date('measured_on');
            $table->decimal('weight_kg', 4, 1);
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['resident_id', 'measured_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weight_records');
    }
};
