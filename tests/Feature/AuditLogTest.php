<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Facility;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * 編集履歴。
 *
 * ご利用者情報と職員アカウントは、誰がいつ何を変えたのかを後から辿れる
 * 必要がある。要介護度や既往歴の書き換えは、記録の読み方そのものを変える。
 * 職員の役割変更は、誰が何を編集できるかを変える。
 */
class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    private Facility $facility;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facility = Facility::factory()->create();
        $this->admin = User::factory()->create([
            'facility_id' => $this->facility->id,
            'role' => UserRole::Admin,
        ]);
    }

    // ---------------------------------------------------------------
    // 記録される内容
    // ---------------------------------------------------------------

    public function test_ご利用者の変更が誰の操作として残る(): void
    {
        $resident = Resident::factory()->for($this->facility)->create(['name' => '佐藤 ハナ']);

        $this->actingAs($this->admin)->put("/residents/{$resident->id}", [
            'name' => '佐藤 ハナ',
            'name_kana' => 'サトウ ハナ',
            'medical_history' => '変形性膝関節症・高血圧',
        ]);

        $log = AuditLog::query()->for($resident)->where('event', 'updated')->latest('id')->firstOrFail();

        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertArrayHasKey('medical_history', $log->changes);
        $this->assertSame('変形性膝関節症・高血圧', $log->changes['medical_history']['after']);
    }

    public function test_変更前の値も残る(): void
    {
        // 「何に変わったか」だけでは、元が何だったのか分からない
        $resident = Resident::factory()->for($this->facility)->create(['name_kana' => 'サトウ ハナ']);

        $this->actingAs($this->admin)->put("/residents/{$resident->id}", [
            'name' => '田中 ハナ',
            'name_kana' => 'タナカ ハナ',
        ]);

        $log = AuditLog::query()->for($resident)->where('event', 'updated')->latest('id')->firstOrFail();

        $this->assertSame('サトウ ハナ', $log->changes['name_kana']['before']);
        $this->assertSame('タナカ ハナ', $log->changes['name_kana']['after']);
    }

    public function test_職員の役割変更が残る(): void
    {
        // 誰が何を編集できるかが変わる。いつ変わったのかを辿れる必要がある。
        $target = User::factory()->create([
            'facility_id' => $this->facility->id,
            'role' => UserRole::Staff,
        ]);

        $this->actingAs($this->admin)->put("/staff/{$target->id}", [
            'name' => $target->name,
            'email' => $target->email,
            'role' => 'manager',
            'is_active' => '1',
        ]);

        $log = AuditLog::query()->for($target)->where('event', 'updated')->latest('id')->firstOrFail();

        $this->assertSame('staff', $log->changes['role']['before']);
        $this->assertSame('manager', $log->changes['role']['after']);
    }

    public function test_登録も履歴に残る(): void
    {
        $this->actingAs($this->admin)->post('/residents', [
            'name' => '大西 ゆき',
            'name_kana' => 'オオニシ ユキ',
        ]);

        $resident = Resident::query()->firstOrFail();
        $log = AuditLog::query()->for($resident)->where('event', 'created')->firstOrFail();

        $this->assertSame('大西 ゆき', $log->changes['name']['after']);
        // 登録時は変更前が存在しない
        $this->assertNull($log->changes['name']['before']);
    }

    public function test_変わっていなければ履歴を残さない(): void
    {
        // 保存しただけで履歴が積まれると、本当に変わった回数が分からなくなる
        $resident = Resident::factory()->for($this->facility)->create([
            'name' => '佐藤 ハナ',
            'name_kana' => 'サトウ ハナ',
        ]);

        AuditLog::query()->delete();

        $this->actingAs($this->admin)->put("/residents/{$resident->id}", [
            'name' => '佐藤 ハナ',
            'name_kana' => 'サトウ ハナ',
        ]);

        $this->assertSame(0, AuditLog::query()->where('event', 'updated')->count());
    }

    // ---------------------------------------------------------------
    // 残さないもの
    // ---------------------------------------------------------------

    public function test_パスワードは履歴に残さない(): void
    {
        // 履歴は管理者が閲覧できる。そこへ認証情報の痕跡を置くと、
        // 履歴そのものが新しい攻撃面になる。
        $target = User::factory()->create(['facility_id' => $this->facility->id]);

        $this->actingAs($this->admin)->put("/staff/{$target->id}", [
            'name' => $target->name,
            'email' => $target->email,
            'role' => 'staff',
            'is_active' => '1',
            'password' => 'Password!234567',
            'password_confirmation' => 'Password!234567',
        ]);

        foreach (AuditLog::query()->for($target)->get() as $log) {
            $this->assertArrayNotHasKey('password', $log->changes ?? []);
            $this->assertArrayNotHasKey('remember_token', $log->changes ?? []);
        }
    }

    public function test_カナの索引は履歴に残さない(): void
    {
        // 暗号化した値から機械的に導くもので、カナの変更として別途残る
        $resident = Resident::factory()->for($this->facility)->create();

        $this->actingAs($this->admin)->put("/residents/{$resident->id}", [
            'name' => $resident->name,
            'name_kana' => 'タナカ ハナ',
        ]);

        $log = AuditLog::query()->for($resident)->where('event', 'updated')->latest('id')->firstOrFail();

        $this->assertArrayNotHasKey('name_kana_hash', $log->changes);
    }

    // ---------------------------------------------------------------
    // 保護
    // ---------------------------------------------------------------

    public function test_変更内容は暗号化して保存する(): void
    {
        // 氏名や既往歴は residents 側で暗号化している。その変更履歴を
        // 平文で積むと、履歴のほうが弱い漏えい経路になる。
        $resident = Resident::factory()->for($this->facility)->create();

        $this->actingAs($this->admin)->put("/residents/{$resident->id}", [
            'name' => '田中 ハナ',
            'name_kana' => 'タナカ ハナ',
            'medical_history' => '糖尿病',
        ]);

        $raw = DB::table('audit_logs')->latest('id')->first();

        $this->assertStringNotContainsString('田中 ハナ', $raw->changes);
        $this->assertStringNotContainsString('糖尿病', $raw->changes);
    }

    // ---------------------------------------------------------------
    // 閲覧
    // ---------------------------------------------------------------

    public function test_管理者は閲覧できる(): void
    {
        $this->actingAs($this->admin)->get('/audit-logs')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('audit-logs/index'));
    }

    public function test_生活相談員は閲覧できない(): void
    {
        // 変更前の値まで見えるため、日々の介護業務で開く必要はない
        $manager = User::factory()->create([
            'facility_id' => $this->facility->id,
            'role' => UserRole::Manager,
        ]);

        $this->actingAs($manager)->get('/audit-logs')->assertForbidden();
    }

    public function test_他の事業所の履歴は見えない(): void
    {
        $outsider = Resident::factory()->for(Facility::factory()->create())->create();
        $outsider->update(['name' => '別事業所 太郎']);

        $mine = Resident::factory()->for($this->facility)->create(['name' => '佐藤 ハナ']);

        $this->actingAs($this->admin)->get('/audit-logs')
            ->assertInertia(function (AssertableInertia $page) use ($mine) {
                $names = collect($page->toArray()['props']['logs']['data'])
                    ->pluck('subjectName');

                $this->assertTrue($names->contains('佐藤 ハナ 様'));
                $this->assertFalse($names->contains('別事業所 太郎 様'));
                // 対象が別事業所のものは、行ごと落としている
                $this->assertFalse($names->contains('（削除済み）'));

                $this->assertNotNull($mine->id);
            });
    }

    public function test_種別で絞り込める(): void
    {
        Resident::factory()->for($this->facility)->create();

        $this->actingAs($this->admin)->get('/audit-logs?type=staff')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('type', 'staff')
                // ご利用者の登録は職員の履歴には出ない
                ->has('logs.data', 1)
                ->where('logs.data.0.subjectKind', 'staff')
            );
    }

    public function test_項目名を日本語で渡す(): void
    {
        // 列名のまま出しても、誰が読んでも分かるわけではない
        $resident = Resident::factory()->for($this->facility)->create();

        $this->actingAs($this->admin)->put("/residents/{$resident->id}", [
            'name' => $resident->name,
            'name_kana' => 'タナカ ハナ',
        ]);

        $this->actingAs($this->admin)->get('/audit-logs?type=residents')
            ->assertInertia(function (AssertableInertia $page) {
                $labels = collect($page->toArray()['props']['logs']['data'])
                    ->flatMap(fn (array $log) => array_column($log['changes'], 'label'));

                $this->assertTrue($labels->contains('お名前（カナ）'));
            });
    }

    public function test_未ログインでは開けない(): void
    {
        $this->get('/audit-logs')->assertRedirect();
    }
}
