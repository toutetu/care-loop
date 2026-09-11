<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * バイタル。
 *
 * 【第1正規形】
 * service_records にバイタルを1組だけ持たせる設計は、来所時・入浴前後など
 * 1日に複数回測定する実態に合わない。繰り返し項目を別テーブルへ分離する。
 *
 * 値はすべて nullable。測っていないことと、ゼロだったことは違うため、
 * 未測定を 0 で埋めない。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vital_signs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_record_id')->constrained()->cascadeOnDelete();
            $table->timestamp('measured_at');
            $table->string('timing', 20)->nullable()
                ->comment('arrival / pre_bath / post_bath / departure');

            $table->decimal('temperature', 3, 1)->nullable()->comment('体温 ℃');
            $table->unsignedSmallInteger('systolic_bp')->nullable()->comment('収縮期血圧');
            $table->unsignedSmallInteger('diastolic_bp')->nullable()->comment('拡張期血圧');
            $table->unsignedSmallInteger('pulse')->nullable()->comment('脈拍 回/分');
            $table->unsignedTinyInteger('spo2')->nullable()->comment('SpO2 %');

            $table->timestamps();

            $table->index(['service_record_id', 'measured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vital_signs');
    }
};
