<?php

namespace App\Console\Commands;

use Database\Seeders\CareLevelSeeder;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * デモデータを作り直す。
 *
 * 【なぜ専用のコマンドが要るか】
 * 公開しているデモは、見に来た人が記録を書き換える。数週間たつと、
 * 説明したい筋書き（佐藤ハナの体重減少とふらつき、中村みつの微熱）が
 * 崩れて、何を見せたい画面なのか分からなくなる。
 *
 * 本番環境では migrate:fresh と db:wipe を禁止している
 * （AppServiceProvider の prohibitDestructiveCommands）。
 * 取り違えて本物のデータを消すことを防ぐためであり、この方針は変えない。
 * デモデータの入れ直しだけを行うコマンドを、別に用意する。
 *
 * 【デモ環境でしか動かさない】
 * このコマンドはご利用者も記録も職員も消す。本物の事業所のデータベースへ
 * 向けて実行されたら取り返しがつかない。環境変数 CARELOOP_DEMO が
 * 立っていなければ、確認を出す前に断る。
 * 「気をつける」ではなく、動かないようにしておく。
 */
class ResetDemoData extends Command
{
    protected $signature = 'careloop:reset-demo {--force : 確認を省略する}';

    protected $description = 'デモ用のデータを削除して作り直します（利用者・記録・AI実行履歴）';

    /** 消す順序。外部キーの参照先を後に置く。 */
    private const TABLES = [
        // 編集履歴も消す。残したままにすると、採番し直されたIDを指して
        // 別のご利用者の履歴として表示されてしまう。
        'audit_logs',
        'goal_progress_items',
        'goal_progress_reports',
        'risk_findings',
        'risk_assessments',
        'verbal_contact_tasks',
        'incident_reports',
        'weight_records',
        'meal_records',
        'vital_signs',
        'service_records',
        'care_plan_goals',
        'care_plans',
        'llm_requests',
        'llm_jobs',
        'residents',
        'users',
        'facilities',
        'care_levels',
    ];

    public function handle(): int
    {
        if (! config('careloop.is_demo')) {
            $this->components->error(
                'デモ環境ではないため実行しません。'
                .'意図した操作であれば、環境変数 CARELOOP_DEMO=true を設定してください。',
            );

            return self::FAILURE;
        }

        $confirmed = $this->option('force') || $this->confirm(
            'デモデータをすべて削除して作り直します。よろしいですか？',
            false,
        );

        if (! $confirmed) {
            $this->components->warn('中止しました。');

            return self::FAILURE;
        }

        // セッションは users を参照しないが、消えたユーザーのセッションが
        // 残っていると、ログイン済みのまま存在しない利用者を指すことになる。
        $this->truncate([...self::TABLES, 'sessions']);

        $this->callSilent('db:seed', ['--class' => CareLevelSeeder::class, '--force' => true]);
        $this->call('db:seed', ['--class' => DemoDataSeeder::class, '--force' => true]);

        $this->components->info('デモデータを作り直しました。');

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $tables
     */
    private function truncate(array $tables): void
    {
        // truncate は外部キー制約を無視できないため、制約を外して実行する。
        // delete で消すと、IDが連番で始まらずデモの説明がしにくくなる。
        Schema::withoutForeignKeyConstraints(function () use ($tables): void {
            foreach ($tables as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->truncate();
                }
            }
        });
    }
}
