<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 入浴・清拭を1件ずつの記録にする。
 *
 * これまでは service_records.bathing_type の1カラムで、1日に1つしか持てず、
 * 誰がいつ実施したのかも残らなかった。午前に入浴して午後に清拭することも、
 * 入浴を担当した職員が自分の名前で残すこともできない。
 *
 * 既存の値は1件の記録として移す。時刻は分からないので、その日の
 * サービス提供日だけを持たせ、時刻は null にする。分からないものを
 * それらしい時刻で埋めると、後から見たときに測ったのか埋めたのかが
 * 区別できなくなる。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bathing_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_record_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recorded_by')->nullable()
                ->comment('この記録を入力した職員')
                ->constrained('users')->nullOnDelete();

            $table->timestamp('bathed_at')->nullable()->comment('実施した時刻。不明なら null');
            $table->string('bathing_type', 10)->comment('bath / wipe / none');
            $table->text('note')->nullable();

            $table->timestamps();

            $table->index(['service_record_id', 'bathed_at']);
        });

        // 既存の1カラムぶんを移す。bathing_type が null（未記録）の分は作らない。
        $existing = DB::table('service_records')
            ->whereNotNull('bathing_type')
            ->select('id', 'recorded_by', 'created_at', 'updated_at', 'bathing_type')
            ->get();

        foreach ($existing->chunk(200) as $chunk) {
            DB::table('bathing_records')->insert(
                $chunk->map(fn ($row): array => [
                    'service_record_id' => $row->id,
                    'recorded_by' => $row->recorded_by,
                    'bathed_at' => null,
                    'bathing_type' => $row->bathing_type,
                    'note' => null,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ])->all()
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bathing_records');
    }
};
