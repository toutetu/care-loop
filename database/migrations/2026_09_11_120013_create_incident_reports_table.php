<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ヒヤリハット・事故報告。
 *
 * ルールベースのリスク判定に使う（直近3ヶ月で2件以上 → 転倒リスク）。
 *
 * family_notified を持つ理由：介護保険制度上、事故・ヒヤリハットには
 * ご家族への報告義務がある。報告したかどうかを記録として残せない設計は
 * 制度要件を満たさない。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incident_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resident_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_record_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('category', 30)
                ->comment('fall / choking / medication / wandering / skin / other');
            $table->string('severity', 20)->comment('near_miss / incident / accident');
            $table->timestamp('occurred_at');

            $table->text('description')->comment('発生時の状況');
            $table->text('response')->nullable()->comment('とった対応');
            $table->text('prevention')->nullable()->comment('再発防止策');

            $table->boolean('family_notified')->default(false);
            $table->timestamp('family_notified_at')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['resident_id', 'occurred_at']);
            $table->index(['category', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_reports');
    }
};
