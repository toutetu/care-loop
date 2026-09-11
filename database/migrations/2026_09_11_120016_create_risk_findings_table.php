<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 個々のリスク指摘。
 *
 * 【source を持つ理由（要件定義 7.1節の中核）】
 * 体重減少率や水分摂取量のように数値で判定できるものは決定的なロジックで算出し
 * （rule_based）、「ふらつきの記述が増えている」のような読まないと分からない
 * 質的変化はLLMが抽出する（llm_detected）。
 * ルールベース由来は再現性があり信頼度が高く、LLM由来は要確認という扱いの差を
 * 画面で表現するため、由来を列として保持する。
 *
 * 【evidence を JSON で持つ理由（意図的な非正規化）】
 * LLMが返す根拠の配列は件数も構造も可変で、リレーショナルに展開しても
 * 検索要件がない。正規化しない判断（要件定義 9.2節）。
 * evidence を必須項目とすることで、根拠のない主張を構造的に禁止している。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('risk_assessment_id')->constrained()->cascadeOnDelete();

            $table->string('category', 30)
                ->comment('fall / aspiration / dehydration / malnutrition / pressure_ulcer / infection / medication / skin / bpsd / other');
            $table->string('severity', 10)->comment('high / medium / low');
            $table->string('source', 20)->comment('rule_based / llm_detected / both');

            $table->string('title');
            $table->text('reason');

            $table->json('evidence')->nullable()->comment('根拠となる記録ID・日付・該当箇所');
            $table->json('suggested_actions')->nullable()->comment('現場での確認事項');

            $table->timestamps();

            $table->index(['risk_assessment_id', 'severity']);
            $table->index(['category', 'severity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_findings');
    }
};
