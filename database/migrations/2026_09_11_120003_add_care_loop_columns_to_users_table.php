<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 職員（users）に CareLoop 用の列を追加する。
 *
 * role は staff / manager / admin の3種。
 * ご家族向け報告の「承認」を manager 以上に限定することで、AIが生成した文章が
 * 誰の確認も経ずにご家族へ渡らないことを権限設計のレベルで保証する
 * （要件定義 4.2節）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('facility_id')->nullable()->after('id')
                ->constrained()->nullOnDelete();
            $table->string('role', 20)->default('staff')->after('email')
                ->comment('staff / manager / admin');
            $table->boolean('is_active')->default(true)->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('facility_id');
            $table->dropColumn(['role', 'is_active']);
        });
    }
};
