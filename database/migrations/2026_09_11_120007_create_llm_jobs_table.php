<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LLM実行ジョブ（F-LLM-06）。
 *
 * LLMの応答には数秒から数十秒かかるため、HTTPリクエスト内で同期実行すると
 * タイムアウトとUXの両面で破綻する。すべてのLLM呼び出しは非同期ジョブとして
 * 実行し、フロントエンドは job_id をポーリングして進捗を表示する。
 *
 * status: queued -> running -> succeeded / failed
 * 失敗したジョブは管理画面から再実行できる。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('llm_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('feature', 30)->comment('F-LLM-01 〜 F-LLM-05');

            // 対象を多態で持つ（Resident / ServiceRecord など）
            $table->string('target_type', 60)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();

            $table->date('period_from')->nullable()->comment('集計対象期間');
            $table->date('period_to')->nullable();

            $table->string('status', 20)->default('queued')
                ->comment('queued / running / succeeded / failed');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();

            $table->json('result')->nullable()->comment('検証済みの構造化レスポンス');
            $table->string('error_type', 50)->nullable()
                ->comment('rate_limit_error / schema_mismatch / json_parse_error など');
            $table->text('error_message')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['feature', 'status']);
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('llm_jobs');
    }
};
