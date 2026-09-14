<?php

namespace Tests\Feature;

use App\Console\Commands\ResetDemoData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Tests\TestCase;

/**
 * デモデータの作り直し。
 *
 * 【消し忘れが静かに壊す】
 * truncate は外部キー制約を外して実行している。消す表の一覧から漏れても
 * エラーにはならず、残った行がそのまま居座る。service_records は1から
 * 採番し直されるので、残った行は別のご利用者の記録を指すことになる。
 *
 * 表を足した人が一覧への追記を忘れても気づけるよう、構造で確かめる。
 */
class ResetDemoDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_記録にぶら下がる表がすべて消去対象に入っている(): void
    {
        $tables = $this->truncatedTables();
        $missing = [];

        foreach ($this->tablesReferencing('service_record_id') as $table) {
            if (! in_array($table, $tables, true)) {
                $missing[] = $table;
            }
        }

        $this->assertSame(
            [],
            $missing,
            'service_record_id を持つ表が ResetDemoData の消去対象から漏れている: '
            .implode(', ', $missing),
        );
    }

    public function test_利用者にぶら下がる表がすべて消去対象に入っている(): void
    {
        $tables = $this->truncatedTables();
        $missing = [];

        foreach ($this->tablesReferencing('resident_id') as $table) {
            if (! in_array($table, $tables, true)) {
                $missing[] = $table;
            }
        }

        $this->assertSame(
            [],
            $missing,
            'resident_id を持つ表が ResetDemoData の消去対象から漏れている: '
            .implode(', ', $missing),
        );
    }

    public function test_消す順序は子から親へ(): void
    {
        // 外部キー制約を外しているので順序を間違えても落ちないが、
        // 制約を戻したときに気づけなくなる。親を先に置かない。
        $tables = $this->truncatedTables();

        $this->assertLessThan(
            array_search('service_records', $tables, true),
            array_search('bathing_records', $tables, true),
            'bathing_records は service_records より先に消す',
        );

        $this->assertLessThan(
            array_search('service_records', $tables, true),
            array_search('record_notes', $tables, true),
            'record_notes は service_records より先に消す',
        );
    }

    // ---------------------------------------------------------------

    /** @return list<string> */
    private function truncatedTables(): array
    {
        /** @var list<string> $tables */
        $tables = (new ReflectionClass(ResetDemoData::class))
            ->getConstant('TABLES');

        return $tables;
    }

    /**
     * 指定した列を持つ表を集める。移行で表が増えても自動で対象に入る。
     *
     * @return list<string>
     */
    private function tablesReferencing(string $column): array
    {
        $found = [];

        foreach (Schema::getTableListing() as $table) {
            // スキーマ名が前に付く環境があるため、最後の区切り以降を見る
            $name = str_contains($table, '.')
                ? substr($table, strrpos($table, '.') + 1)
                : $table;

            if (Schema::hasColumn($name, $column)) {
                $found[] = $name;
            }
        }

        return $found;
    }
}
