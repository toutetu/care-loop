<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 音声入力・手入力の原文を、1件ずつ記録者つきで残す。
 *
 * これまでは service_records.raw_note の1カラムで、あとから入力した職員が
 * 前の職員の原文を上書きしていた。送迎担当が書いた内容が、入浴担当の入力で
 * 消える。誰が言ったことなのかも残らない。
 *
 * 原文はAIが何を変えたのかを後から検証するための原本なので、書き換えず
 * 積むだけにする。訂正は新しい1件として足す。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('record_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_record_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recorded_by')->nullable()
                ->comment('この原文を入力した職員')
                ->constrained('users')->nullOnDelete();

            $table->text('body')->comment('入力された原文。書き換えない');
            $table->string('input_method', 10)->default('keyboard')
                ->comment('voice / keyboard。音声入力か手入力か');

            $table->timestamps();

            $table->index(['service_record_id', 'created_at']);
        });

        // 既存の原文を1件目として移す。
        $existing = DB::table('service_records')
            ->whereNotNull('raw_note')
            ->where('raw_note', '<>', '')
            ->select('id', 'recorded_by', 'raw_note', 'created_at', 'updated_at')
            ->get();

        foreach ($existing->chunk(200) as $chunk) {
            DB::table('record_notes')->insert(
                $chunk->map(fn ($row): array => [
                    'service_record_id' => $row->id,
                    'recorded_by' => $row->recorded_by,
                    'body' => $row->raw_note,
                    // 既存分は音声か手入力かが判別できない。推測で voice と
                    // 書くと、あとから「音声で入れた」という誤った事実が残る。
                    'input_method' => 'keyboard',
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ])->all()
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('record_notes');
    }
};
