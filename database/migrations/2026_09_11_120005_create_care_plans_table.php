<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 通所介護計画書。1人の利用者が期間の異なる計画書を複数持つ。
 * 長期目標はこのテーブル、短期目標は care_plan_goals に分離する（第1正規形）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('care_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resident_id')->constrained()->cascadeOnDelete();
            $table->date('period_from');
            $table->date('period_to');
            $table->text('long_term_goal');
            $table->string('status', 20)->default('active')->comment('draft / active / archived');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['resident_id', 'period_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('care_plans');
    }
};
