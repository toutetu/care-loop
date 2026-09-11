<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 通所介護計画 目標進捗要約（F-LLM-01）の実行単位。
 * 目標ごとの評価は goal_progress_items に持つ。
 *
 * モニタリング（計画書の目標に対する達成状況の振り返り）の下書きとして使う。
 * 従来は1〜3ヶ月分の記録を人手で読み返して要約しており、担当者の記憶と主観に
 * 依存しやすかった。その作業の出発点を機械化する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goal_progress_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resident_id')->constrained()->cascadeOnDelete();
            $table->foreignId('care_plan_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('llm_job_id')->nullable()->constrained()->nullOnDelete();

            $table->date('period_from');
            $table->date('period_to');

            $table->text('overall_summary')->nullable()->comment('期間全体の総括');
            $table->json('next_actions')->nullable()->comment('次期に向けた提案');
            $table->string('confidence', 10)->nullable()->comment('high / medium / low');

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();

            $table->index(['resident_id', 'period_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goal_progress_reports');
    }
};
