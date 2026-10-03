<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Announcement;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * 管理者からの周知。
 *
 * 出せるのは管理者以上。職員が「確認しました」を押すまでお知らせに残り、
 * 誰がまだ確認していないかが分かること。開いただけでは確認にならないこと。
 */
class AnnouncementTest extends TestCase
{
    use RefreshDatabase;

    private Facility $facility;

    private User $staff;

    private User $colleague;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facility = Facility::factory()->create();
        $this->staff = $this->member(UserRole::Staff);
        $this->colleague = $this->member(UserRole::Staff);
        $this->manager = $this->member(UserRole::Manager);
    }

    public function test_周知を出せるのは管理者以上(): void
    {
        $this->actingAs($this->staff)
            ->post('/announcements', ['title' => '件名', 'body' => '本文'])
            ->assertForbidden();

        $this->actingAs($this->manager)
            ->post('/announcements', ['title' => '送迎車内の換気', 'body' => '窓を少し開けてください', 'is_important' => '1'])
            ->assertRedirect(route('announcements.index'));

        $announcement = Announcement::query()->sole();
        $this->assertSame('送迎車内の換気', $announcement->title);
        $this->assertTrue($announcement->is_important);
        $this->assertSame($this->manager->id, $announcement->user_id);
    }

    public function test_確認するまでお知らせに残り件数に数える(): void
    {
        $announcement = $this->announce();

        $this->actingAs($this->staff)->get('/notices')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('announcements', 1)
                ->where('announcements.0.title', '送迎車内の換気')
                ->where('noticeCount', 1)
            );

        $this->actingAs($this->staff)
            ->post("/announcements/{$announcement->id}/confirm")
            ->assertRedirect();

        $this->actingAs($this->staff)->get('/notices')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('announcements', 0)
                ->where('noticeCount', 0)
            );
    }

    public function test_開いただけでは確認にしない(): void
    {
        // スクロールで通り過ぎただけで「周知済み」になると、確認の意味がない
        $this->announce();

        $this->actingAs($this->staff)->get('/announcements');
        $this->actingAs($this->staff)->get('/notices');

        $this->assertSame(0, DB::table('announcement_reads')->count());
    }

    public function test_自分が出した周知は確認の対象にしない(): void
    {
        $this->announce();

        $this->actingAs($this->manager)->get('/notices')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('announcements', 0));
    }

    public function test_誰がまだ確認していないかを職員全員に見せる(): void
    {
        // 同じ日に出勤している職員が、まだの人に声をかけられるようにする
        $announcement = $this->announce();
        $announcement->confirm($this->colleague);

        $this->actingAs($this->staff)->get('/announcements')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('announcements/index')
                ->where('announcements.0.targetCount', 2)
                ->where('announcements.0.confirmed.0.name', $this->colleague->name)
                ->where('announcements.0.unconfirmed', [$this->staff->name])
                ->where('announcements.0.confirmedAt', null)
            );
    }

    public function test_二度押しても確認は1件(): void
    {
        $announcement = $this->announce();

        $this->actingAs($this->staff)->post("/announcements/{$announcement->id}/confirm");
        $this->actingAs($this->staff)->post("/announcements/{$announcement->id}/confirm");

        $this->assertSame(1, $announcement->reads()->count());
    }

    public function test_他の事業所の周知は出ず確認もできない(): void
    {
        $other = Announcement::query()->create([
            'facility_id' => Facility::factory()->create()->id,
            'title' => '他の事業所',
            'body' => '本文',
        ]);

        $this->actingAs($this->staff)->get('/notices')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('announcements', 0));

        $this->actingAs($this->staff)
            ->post("/announcements/{$other->id}/confirm")
            ->assertForbidden();
    }

    public function test_直す手段も消す手段もない(): void
    {
        $announcement = $this->announce();

        // 周知1件を指す URL は「確認しました」しかない
        $this->actingAs($this->manager)->put("/announcements/{$announcement->id}", ['title' => '書き換え'])
            ->assertNotFound();
        $this->actingAs($this->manager)->delete("/announcements/{$announcement->id}")
            ->assertNotFound();

        $this->assertSame('送迎車内の換気', $announcement->refresh()->title);
    }

    public function test_件名と本文は暗号化して保存する(): void
    {
        $this->announce();

        $row = DB::table('announcements')->first();

        $this->assertNotNull($row);
        $this->assertStringNotContainsString('換気', (string) $row->title);
        $this->assertStringNotContainsString('窓', (string) $row->body);
    }

    private function announce(): Announcement
    {
        return Announcement::query()->create([
            'facility_id' => $this->facility->id,
            'user_id' => $this->manager->id,
            'title' => '送迎車内の換気',
            'body' => '窓を少し開けてください',
        ]);
    }

    private function member(UserRole $role): User
    {
        return User::factory()->create([
            'facility_id' => $this->facility->id,
            'role' => $role,
            'is_active' => true,
        ]);
    }
}
