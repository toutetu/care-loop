<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LLM呼び出しの監査ログ（F-LLM-07）。1ジョブが複数リクエストを持つ（リトライ分）。
 *
 * 【masked_request / masked_response に保存するのはマスキング後の内容のみ】
 * 個人情報をプレースホルダに置換したあとのペイロードだけを残す。
 * ログ経由で個人情報が二次流出することを防ぐ（要件定義 7.2節）。
 *
 * 【estimated_cost_usd をあえて冗長に保存する理由】
 * トークン数から計算できる導出値だが、料金体系はモデルごとに異なり将来改定もある。
 * 「実行時点の単価で計算した結果」は、あとから再現できない値であるため保存する。
 * 正規化を意図的に崩す判断（要件定義 9.2節）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('llm_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('llm_job_id')->nullable()->constrained()->nullOnDelete();
            $table->string('feature', 30);
            $table->string('model', 60);
            $table->string('prompt_version', 20)->nullable()
                ->comment('プロンプト変更による出力品質の変化を追跡するため');

            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cache_read_input_tokens')->default(0)
                ->comment('プロンプトキャッシュが実際に効いているかの検証に使う');
            $table->unsignedInteger('cache_creation_input_tokens')->default(0);

            $table->unsignedInteger('latency_ms')->default(0);
            $table->string('status', 20)->comment('success / failed');
            $table->string('error_type', 50)->nullable();
            $table->unsignedTinyInteger('retry_count')->default(0);
            $table->string('stop_reason', 30)->nullable()
                ->comment('end_turn / max_tokens / refusal など');

            $table->decimal('estimated_cost_usd', 12, 6)->default(0);

            $table->longText('masked_request')->nullable()->comment('マスキング後のみ');
            $table->longText('masked_response')->nullable();

            $table->timestamps();

            $table->index(['feature', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('llm_requests');
    }
};
