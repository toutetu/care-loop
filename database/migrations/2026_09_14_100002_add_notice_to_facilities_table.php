<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 事業所からのお知らせを追加する。
 *
 * 行事の予定や季節のあいさつを、連絡帳に載せるための欄。
 * ご利用者ごとではなく事業所ごとに1つ持ち、管理者が書き換えると
 * 以降に発行する連絡帳へ反映される。
 *
 * 日付ごとに管理する設計にはしない。行事予定は数週間単位で変わるもので、
 * 毎日入れ替える運用は現場の負担になるだけで、効果に見合わない。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facilities', function (Blueprint $table) {
            $table->text('notice')->nullable()->after('capacity')
                ->comment('連絡帳に載せるお知らせ。行事予定・季節のあいさつなど');
        });
    }

    public function down(): void
    {
        Schema::table('facilities', function (Blueprint $table) {
            $table->dropColumn('notice');
        });
    }
};
