<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * サービス提供記録。介護保険法上、作成と保存が義務づけられた法定文書。
 *
 * 【音声入力とAI整形のため4種類のテキストを保持する（要件定義 9.1節）】
 *   raw_note      … 音声入力された原文。未加工のまま保存する
 *   record_text   … 記録用に整形（法定文書の本体）
 *   family_text   … ご家族向けに整形
 *   handover_note … 申し送り用
 *
 * 【raw_note を必ず残す理由】
 * AIが何を変えたのかを後から検証できない記録は、法定文書として使えない。
 * 原文と整形結果を突き合わせられることが記録の信頼性を担保する。
 * 整形結果に誤りが見つかったときの原因追跡（プロンプトの問題か、入力が曖昧だったか）
 * にも必要である。
 *
 * 【削除の方針（要件定義 8章）】
 * 業務操作による削除は論理削除（softDeletes）。職員の操作で法定文書が消えてはならない。
 * 法定保存期間を経過した記録は、別途バッチで物理削除する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resident_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->date('service_date');
            $table->time('arrival_time')->nullable();
            $table->time('departure_time')->nullable();
            $table->string('attendance_status', 20)->default('attended')
                ->comment('attended / absent / cancelled');
            $table->string('absence_reason')->nullable();

            // 1日を通しての合計。おやつや随時の提供も含むため記録単位で持つ
            $table->unsignedSmallInteger('total_water_ml')->nullable()->comment('水分摂取量の合計');

            // --- 音声入力とAI整形 ---
            $table->text('raw_note')->nullable()->comment('音声入力の原文。未加工');
            $table->text('record_text')->nullable()->comment('記録用に整形');
            $table->text('family_text')->nullable()->comment('ご家族向けに整形');
            $table->text('handover_note')->nullable()->comment('申し送り用');
            $table->boolean('record_text_edited_by_human')->default(false);
            $table->boolean('family_text_edited_by_human')->default(false);
            $table->foreignId('llm_job_id')->nullable()->constrained()->nullOnDelete()
                ->comment('どのAI実行で生成したか');

            $table->timestamp('confirmed_at')->nullable()->comment('職員が内容を確認して確定した時刻');

            $table->softDeletes();
            $table->timestamps();

            // 1利用者 × 1サービス提供日 につき1レコード
            $table->unique(['resident_id', 'service_date']);
            $table->index('service_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_records');
    }
};
