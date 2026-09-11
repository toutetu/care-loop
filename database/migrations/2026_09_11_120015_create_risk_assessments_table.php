<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * リスク兆候の抽出（F-LLM-02）の実行単位。個々の指摘は risk_findings に持つ。
 *
 * reviewed_by / reviewed_at を持つのは、AIの出力が「下書き・気づきの提示」であり、
 * 人が確認して初めて意味を持つという設計方針（Human-in-the-Loop）を
 * データ構造として表現するため。未確認のまま放置された抽出結果を識別できる。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resident_id')->constrained()->cascadeOnDelete();
            $table->foreignId('llm_job_id')->nullable()->constrained()->nullOnDelete();

            $table->date('period_from');
            $table->date('period_to');
            $table->timestamp('assessed_at');

            $table->boolean('no_risk_detected')->default(false);
            $table->string('confidence', 10)->nullable()->comment('high / medium / low');

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->comment('職員が確認した時刻');

            $table->timestamps();

            $table->index(['resident_id', 'assessed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_assessments');
    }
};
