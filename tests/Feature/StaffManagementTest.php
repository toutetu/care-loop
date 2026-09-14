<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * 職員アカウントの管理。
 *
 * アカウントを作れるということは、記録を書ける人を増やせるということである。
 * 介護記録は法定の保存文書であり、誰が書いたかを追えることが前提になる。
 */
class StaffManagementTest extends TestCase
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
    // 権限
    // ---------------------------------------------------------------

    public function test_管理者は職員を追加できる(): void
    {
        $this->actingAs($this->admin)->get('/staff/create')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('staff/form'));
    }

    public function test_生活相談員は職員を追加できない(): void
    {
        // 権限の付与を現場の判断で行える状態にはしない
        $manager = User::factory()->create([
            'facility_id' => $this->facility->id,
            'role' => UserRole::Manager,
        ]);

        $this->actingAs($manager)->get('/staff')->assertForbidden();
        $this->actingAs($manager)->get('/staff/create')->assertForbidden();

        $this->actingAs($manager)->post('/staff', [
            'name' => '誰か',
            'email' => 'someone@example.com',
            'role' => 'staff',
            'password' => 'Password!234567',
            'password_confirmation' => 'Password!234567',
        ])->assertForbidden();
    }

    public function test_介護職員は職員を追加できない(): void
    {
        $staff = User::factory()->create([
            'facility_id' => $this->facility->id,
            'role' => UserRole::Staff,
        ]);

        $this->actingAs($staff)->get('/staff')->assertForbidden();
    }

    // ---------------------------------------------------------------
    // 登録
    // ---------------------------------------------------------------

    public function test_職員を追加すると自分の事業所に所属する(): void
    {
        $this->actingAs($this->admin)->post('/staff', [
            'name' => '山口 みどり',
            'email' => 'yamaguchi@example.com',
            'role' => 'staff',
            'password' => 'Password!234567',
            'password_confirmation' => 'Password!234567',
        ])->assertRedirect('/staff');

        $created = User::query()->where('email', 'yamaguchi@example.com')->firstOrFail();

        $this->assertSame($this->facility->id, $created->facility_id);
        $this->assertSame(UserRole::Staff, $created->role);
        $this->assertTrue($created->is_active);
        $this->assertTrue(Hash::check('Password!234567', $created->password));
    }

    public function test_同じメールアドレスでは追加できない(): void
    {
        // 同じメールで2つのアカウントがあると、記録の書き手を追えなくなる
        $this->actingAs($this->admin)
            ->from('/staff/create')
            ->post('/staff', [
                'name' => '別の人',
                'email' => $this->admin->email,
                'role' => 'staff',
                'password' => 'Password!234567',
                'password_confirmation' => 'Password!234567',
            ])
            ->assertSessionHasErrors('email');
    }

    public function test_確認用と一致しないパスワードは受け付けない(): void
    {
        $this->actingAs($this->admin)
            ->from('/staff/create')
            ->post('/staff', [
                'name' => '山口 みどり',
                'email' => 'yamaguchi@example.com',
                'role' => 'staff',
                'password' => 'Password!234567',
                'password_confirmation' => 'Different!234567',
            ])
            ->assertSessionHasErrors('password');
    }

    // ---------------------------------------------------------------
    // 編集
    // ---------------------------------------------------------------

    public function test_自分自身は編集できない(): void
    {
        // 管理者が誤って自分を介護職員に落とすと、誰も権限を戻せなくなる。
        // 事業所に管理者が1人しかいない状況は珍しくない。
        $this->actingAs($this->admin)->get("/staff/{$this->admin->id}/edit")
            ->assertForbidden();
    }

    public function test_一覧では自分を編集不可として渡す(): void
    {
        $this->actingAs($this->admin)->get('/staff')
            ->assertInertia(function (AssertableInertia $page) {
                $rows = collect($page->toArray()['props']['staff'])->keyBy('id');

                $this->assertFalse($rows[$this->admin->id]['canEdit']);
                $this->assertTrue($rows[$this->admin->id]['isSelf']);
            });
    }

    public function test_パスワードを空欄にすれば据え置かれる(): void
    {
        // 変更のたびに入れ直させると、覚えやすいものに変えられてしまう
        $target = User::factory()->create([
            'facility_id' => $this->facility->id,
            'role' => UserRole::Staff,
            'password' => Hash::make('Original!234567'),
        ]);

        $this->actingAs($this->admin)->put("/staff/{$target->id}", [
            'name' => '山口 みどり',
            'email' => $target->email,
            'role' => 'manager',
            'is_active' => true,
            'password' => '',
        ])->assertRedirect('/staff');

        $target->refresh();

        $this->assertSame(UserRole::Manager, $target->role);
        $this->assertTrue(Hash::check('Original!234567', $target->password));
    }

    public function test_退職者は削除せず在籍を外す(): void
    {
        // 削除すると、その職員が書いた記録の記録者が辿れなくなる
        $target = User::factory()->create([
            'facility_id' => $this->facility->id,
            'role' => UserRole::Staff,
        ]);

        $this->actingAs($this->admin)->put("/staff/{$target->id}", [
            'name' => $target->name,
            'email' => $target->email,
            'role' => 'staff',
        ]);

        $this->assertDatabaseHas('users', ['id' => $target->id, 'is_active' => false]);
    }

    public function test_他の事業所の職員は編集できない(): void
    {
        $outsider = User::factory()->create([
            'facility_id' => Facility::factory()->create()->id,
        ]);

        $this->actingAs($this->admin)->get("/staff/{$outsider->id}/edit")->assertForbidden();
    }

    public function test_一覧に他の事業所の職員は出ない(): void
    {
        User::factory()->create(['facility_id' => Facility::factory()->create()->id]);

        $this->actingAs($this->admin)->get('/staff')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('staff', 1));
    }
}
