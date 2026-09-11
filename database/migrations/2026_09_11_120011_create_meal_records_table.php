<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 食事記録。
 *
 * 【第2正規形】
 * このテーブルは「サービス提供記録 × 食事区分（昼食／おやつ）」で一意になる。
 * 複合主キー (service_record_id, meal_type) を採用すると、service_date のように
 * service_record_id のみに従属する項目を持たせた際に部分関数従属が発生する。
 *
 * よってサロゲートキー id を主キーとし、(service_record_id, meal_type) には
 * ユニーク制約を課す。日付等は service_records 側にのみ保持し重複させない。
 *
 * choking（むせ込み）は誤嚥リスクの判定とご家族への口頭連絡タスク生成に使う。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_record_id')->constrained()->cascadeOnDelete();
            $table->string('meal_type', 20)->comment('lunch / snack');

            $table->unsignedTinyInteger('staple_rate')->nullable()->comment('主食の摂取割合 0-100');
            $table->unsignedTinyInteger('side_rate')->nullable()->comment('副菜の摂取割合 0-100');
            $table->string('meal_form', 30)->nullable()->comment('常食 / 一口大 / 刻み / ミキサー');
            $table->boolean('choking')->default(false)->comment('むせ込みの有無');
            $table->text('note')->nullable();

            $table->timestamps();

            $table->unique(['service_record_id', 'meal_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_records');
    }
};
