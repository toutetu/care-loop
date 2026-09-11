<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 短期目標ごとの進捗評価。care_plan_goals の1件に対応する。
 *
 * 【progress_status に insufficient_data を含める理由】
 * 記録が不足している期間に、無理やり「改善」「横ばい」と評価させないため。
 * 「判断できる材料が不足している」と言わせることが、事実に基づかない出力を防ぐ
 * 最も効果的な手段である（要件定義 7.3節）。
 *
 * evidence は必須項目として扱い、根拠のない評価を構造的に禁止する。
 * 画面では根拠記録へのリンクを表示し、職員が原典を即座に確認できるようにする。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goal_progress_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goal_progress_report_id')->constrained()->cascadeOnDelete();
            $table->foreignId('care_plan_goal_id')->nullable()->constrained()->nullOnDelete();

            $table->string('progress_status', 30)
                ->comment('improving / unchanged / declining / insufficient_data');
            $table->text('comment')->nullable();
            $table->json('evidence')->nullable()->comment('根拠となる記録ID・日付・該当箇所');

            $table->timestamps();

            $table->index('goal_progress_report_id', 'gpi_report_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goal_progress_items');
    }
};
