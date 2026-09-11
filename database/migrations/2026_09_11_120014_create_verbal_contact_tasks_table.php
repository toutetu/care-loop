<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 口頭連絡タスクと完了記録（F-20）。
 *
 * 【この機能が存在する理由（要件定義 7.3節 設計判断の変更履歴）】
 * 当初は、ご家族が不安を感じうる事項をご家族向け文書から除外し、口頭で伝える
 * 設計としていた。しかしこの設計では、口頭連絡が漏れたときにご家族へ何も
 * 伝わらない。書きもせず、言いもしないという最悪の結果を許してしまい、
 * さらに伝達の証跡が残らないため「聞いていない」と言われた際に何も示せない。
 *
 * 変更後：ご本人の身体状況に関する事実は必ず文書に記載する。そのうえで、
 * 口頭での補足が必要な事項にこのタスクを発行し、完了を記録する。
 * 未完了のまま日をまたいだ場合は管理者へ通知する。
 *
 * 「連絡帳に書いてあるから伝えた」を仕組みで潰すためのテーブルである。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verbal_contact_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resident_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_record_id')->nullable()->constrained()->nullOnDelete();

            $table->string('topic')->comment('例：食事中のむせ込み');
            $table->text('reason')->comment('なぜ口頭での補足が必要か');
            $table->string('urgency', 20)->default('same_day')->comment('same_day / next_visit');
            $table->string('source', 20)->default('llm')->comment('llm / manual');

            $table->string('status', 20)->default('pending')
                ->comment('pending / completed / cancelled');

            // --- 完了記録：誰が・いつ・どなたに・何を伝えたか ---
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->text('contacted_person')->nullable()->comment('暗号化：どなたにお伝えしたか');
            $table->text('completed_note')->nullable()->comment('何をお伝えしたか');

            $table->timestamps();

            $table->index(['status', 'urgency']);
            $table->index(['resident_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verbal_contact_tasks');
    }
};
