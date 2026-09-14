<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * バイタルと食事に「誰がいつ入れたか」を持たせ、1日に何度でも残せるようにする。
 *
 * 【なぜ複数件を許すか】
 * 通所介護では体温を来所時・入浴前・入浴後と測る。食事も昼食とおやつがあり、
 * 量が変われば書き直すのではなく、その時点の事実として積み上がるべきものである。
 * これまで食事は meal_type ごとに1件へ制限し、バイタルは保存のたびに先頭の1件を
 * 上書きしていた。あとから入れた職員が、前の職員の記録を消していたことになる。
 *
 * 【なぜ記録者を持たせるか】
 * 記録全体の recorded_by は「最初に触れた職員」しか指していなかった。
 * 送迎・入浴・食事で担当が入れ替わる以上、どの1件を誰が入れたのかは
 * 行ごとに持たなければ辿れない。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vital_signs', function (Blueprint $table) {
            $table->foreignId('recorded_by')->nullable()->after('service_record_id')
                ->comment('この測定を入力した職員')
                ->constrained('users')->nullOnDelete();
        });

        Schema::table('meal_records', function (Blueprint $table) {
            $table->foreignId('recorded_by')->nullable()->after('service_record_id')
                ->comment('この記録を入力した職員')
                ->constrained('users')->nullOnDelete();

            $table->timestamp('recorded_at')->nullable()->after('meal_type')
                ->comment('提供・確認した時刻');
        });

        /*
         * 種別ごと1件の制限を外す。おやつを2回に分けて出すこともあり、
         * 昼食の摂取量を食後に入れ直すこともある。どちらも別の事実として残す。
         */
        Schema::table('meal_records', function (Blueprint $table) {
            $table->dropUnique('meal_records_service_record_id_meal_type_unique');
            $table->index(['service_record_id', 'meal_type']);
        });
    }

    public function down(): void
    {
        Schema::table('meal_records', function (Blueprint $table) {
            $table->dropIndex(['service_record_id', 'meal_type']);
            $table->unique(['service_record_id', 'meal_type']);
            $table->dropConstrainedForeignId('recorded_by');
            $table->dropColumn('recorded_at');
        });

        Schema::table('vital_signs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recorded_by');
        });
    }
};
