<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 利用者。要配慮個人情報（健康状態・病歴）を含む唯一のテーブル。
 *
 * 【データ保護設計（要件定義 9.3節）】
 * 前提：データベース設計は情報流出を「防ぐ」ものではない。防ぐのはネットワーク境界・
 * 認証・認可である。ここでの設計は、防御が突破されたときに被害を最小化するための
 * 多層防御の最終層として位置づける。
 *
 * - 個人を特定する列は Laravel の encrypted キャストで暗号化する。
 *   暗号化後の値は元の数倍の長さになるため、型は string ではなく text とする。
 * - 暗号化した列は WHERE / ORDER BY / INDEX が使えない。氏名カナの検索は
 *   name_kana_hash（HMAC-SHA256）による完全一致で代替する ＝ Blind Index。
 *   ただし頻度分析には弱く、完全な対策ではない。
 * - 生年月日は暗号化しない。年齢での絞り込み・並べ替えに使うため。
 * - マイナンバー・金融情報は保持しない（データ最小化。持っていない情報は漏れない）。
 *
 * 【第3正規形】要介護度の名称は care_levels マスタへ分離し care_level_id で参照する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('residents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facility_id')->constrained()->cascadeOnDelete();
            $table->foreignId('care_level_id')->nullable()->constrained()->nullOnDelete();

            // --- 暗号化する列（encrypted キャスト。text 型必須） ---
            $table->text('name')->comment('暗号化');
            $table->text('name_kana')->comment('暗号化');
            $table->text('insurance_number')->nullable()->comment('暗号化：被保険者番号');
            $table->text('address')->nullable()->comment('暗号化');
            $table->text('phone')->nullable()->comment('暗号化');
            $table->text('family_contact')->nullable()->comment('暗号化：緊急連絡先');
            $table->text('medical_history')->nullable()->comment('暗号化：既往歴');

            // --- Blind Index（暗号化した氏名カナを検索するための索引） ---
            $table->string('name_kana_hash', 64)->index()
                ->comment('HMAC-SHA256(正規化カナ, BLIND_INDEX_KEY) の16進表現');

            // --- 暗号化しない列 ---
            $table->date('birth_date')->nullable()->comment('年齢での絞り込みに使うため平文');
            $table->string('gender', 10)->nullable();
            $table->json('service_weekdays')->nullable()->comment('利用曜日 例:[1,3,5]');
            $table->date('started_at')->nullable()->comment('利用開始日');
            $table->date('ended_at')->nullable()->comment('利用終了日');
            $table->string('care_manager_name')->nullable()->comment('担当ケアマネジャー');

            $table->softDeletes();
            $table->timestamps();

            $table->index(['facility_id', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('residents');
    }
};
