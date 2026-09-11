<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 短期目標。
 *
 * 【第1正規形】
 * care_plans に goal_1 / goal_2 / goal_3 と固定列を持たせる設計は繰り返し項目であり
 * 第1正規形に違反する。短期目標の件数は可変で、4件目に対応できないうえ、
 * 目標単位の進捗評価（F-LLM-01）もできない。よって1対多の別テーブルへ分離する。
 *
 * goal_progress_items がこの id を参照し、目標ごとの進捗を記録する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('care_plan_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('care_plan_id')->constrained()->cascadeOnDelete();
            $table->text('goal_text');
            $table->date('target_date')->nullable();
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['care_plan_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('care_plan_goals');
    }
};
