<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 入浴の有無をサービス提供記録に追加する。
 *
 * ご家族が連絡帳で最も気にされる項目のひとつであり、その日に実施したか
 * どうかという単純な事実である。入浴記録の詳細（湯温・皮膚状態など）は
 * 別テーブル（F-10 / Phase 2）で扱うが、実施の有無だけは記録本体に持つ。
 *
 * F-LLM-05 の detected_items はすでに bathing_performed を返している。
 * 抽出していたのに保存先がなかった項目を、ここで受け止める。
 *
 * null は「記録なし」。実施しなかった（false）とは区別する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_records', function (Blueprint $table) {
            $table->boolean('bathing_performed')->nullable()->after('total_water_ml')
                ->comment('入浴の実施有無。null は記録なし');
        });
    }

    public function down(): void
    {
        Schema::table('service_records', function (Blueprint $table) {
            $table->dropColumn('bathing_performed');
        });
    }
};
