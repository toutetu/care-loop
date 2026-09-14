<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 1件しか持てなかった2つのカラムを落とす。
 *
 * 中身は bathing_records / record_notes へ移し終えており、読み手
 * （コントローラ・連絡帳・LLM）もそちらを見るようになっている。
 *
 * 残したままにすると、次に触る人がどちらが正なのか分からなくなる。
 * 1つの事実を2箇所に持つ状態を放置しないため、ここで落とす。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_records', function (Blueprint $table) {
            $table->dropColumn(['bathing_type', 'raw_note']);
        });
    }

    public function down(): void
    {
        Schema::table('service_records', function (Blueprint $table) {
            $table->string('bathing_type', 10)->nullable()->after('total_water_ml')
                ->comment('bath / wipe / none。null は未記録');
            $table->text('raw_note')->nullable()->after('bathing_type')
                ->comment('音声入力の原文。未加工');
        });
    }
};
