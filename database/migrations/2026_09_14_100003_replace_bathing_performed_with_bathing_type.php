<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 入浴の記録を真偽値から3択へ変える。
 *
 * 【なぜ真偽値では足りないのか】
 * 体調や血圧の値によって入浴を見送る日はあるが、その場合も何もしないわけ
 * ではなく、清拭（体を拭くこと）を行う。現場では入浴と清拭は別の行為として
 * 扱われ、記録にもそう残す。真偽値で持つと、この違いが消える。
 *
 * また真偽値のままでは、画面のチェックボックスで「実施しなかった」を
 * 明示できなかった。チェックを外した状態が「未記録」と区別できないためである。
 *
 * 【既存の値を引き継ぐ】
 * true は入浴、false は「入浴・清拭なし」として移す。清拭だったものが
 * 混ざっている可能性はあるが、区別できる情報が残っていない。
 * 分からないものを推測で振り分けるより、元の記録の意味を保つ。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_records', function (Blueprint $table) {
            $table->string('bathing_type', 10)->nullable()->after('total_water_ml')
                ->comment('bath / wipe / none。null は未記録');
        });

        DB::table('service_records')->where('bathing_performed', true)
            ->update(['bathing_type' => 'bath']);

        DB::table('service_records')->where('bathing_performed', false)
            ->update(['bathing_type' => 'none']);

        Schema::table('service_records', function (Blueprint $table) {
            $table->dropColumn('bathing_performed');
        });
    }

    public function down(): void
    {
        Schema::table('service_records', function (Blueprint $table) {
            $table->boolean('bathing_performed')->nullable()->after('total_water_ml')
                ->comment('入浴の実施');
        });

        // 清拭は真偽値で表せない。入浴として戻すと事実が変わるため、
        // 入浴だったものだけを true に戻し、残りは false とする。
        DB::table('service_records')->where('bathing_type', 'bath')
            ->update(['bathing_performed' => true]);

        DB::table('service_records')->whereIn('bathing_type', ['wipe', 'none'])
            ->update(['bathing_performed' => false]);

        Schema::table('service_records', function (Blueprint $table) {
            $table->dropColumn('bathing_type');
        });
    }
};
