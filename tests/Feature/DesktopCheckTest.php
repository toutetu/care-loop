<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Announcement;
use App\Models\Facility;
use App\Models\MessageRoom;
use App\Models\Resident;
use App\Models\ServiceRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * PC で使うときの、記録の確認と本人宛てのお知らせ。
 *
 * PC には下のバーがない。本人宛ての周知と個別の連絡はダッシュボードの先頭に
 * 出ること。記録は一覧へ戻らずに、次の未確定へ進めること。
 */
class DesktopCheckTest extends TestCase
{
    use RefreshDatabase;

    private Facility $facility;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facility = Facility::factory()->create();
        $this->manager = User::factory()->create([
            'facility_id' => $this->facility->id,
            'role' => UserRole::Manager,
        ]);
    }

    public function test_ダッシュボードに本人宛ての周知と個別の連絡を出す(): void
    {
        $admin = User::factory()->create(['facility_id' => $this->facility->id, 'role' => UserRole::Admin]);
        Announcement::query()->create([
            'facility_id' => $this->facility->id,
            'user_id' => $admin->id,
            'title' => '送迎車内の換気',
            'body' => '窓を少し開けてください',
        ]);
        MessageRoom::directBetween($admin, $this->manager)
            ->messages()->create(['user_id' => $admin->id, 'body' => '明日の朝礼で共有します']);

        $this->actingAs($this->manager)->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('announcements.0.title', '送迎車内の換気')
                ->where('directMessages.0.from', $admin->name)
            );
    }

    public function test_次の未確定の記録はカナ順で後ろのものを示す(): void
    {
        $aoki = $this->record('アオキ ハル', confirmed: false);
        $this->record('イトウ キヨ', confirmed: true);
        $ueda = $this->record('ウエダ スミ', confirmed: false);

        // 確定済みの方は飛ばし、次の未確定へ
        $this->actingAs($this->manager)->get("/records/{$aoki->id}/edit")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('nextUnconfirmed.id', $ueda->id)
            );

        // いちばん後ろまで来たら、先頭へ戻って残っているものを示す
        $this->actingAs($this->manager)->get("/records/{$ueda->id}/edit")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('nextUnconfirmed.id', $aoki->id)
            );
    }

    public function test_ほかに未確定がなければ次は出さない(): void
    {
        $only = $this->record('アオキ ハル', confirmed: false);
        $this->record('イトウ キヨ', confirmed: true);

        // 別の日と別の事業所の未確定は数えない
        $this->record('ウエダ スミ', confirmed: false, date: today()->subDay());
        ServiceRecord::factory()
            ->for(Resident::factory()->for(Facility::factory()))
            ->create(['service_date' => today(), 'confirmed_at' => null]);

        $this->actingAs($this->manager)->get("/records/{$only->id}/edit")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('nextUnconfirmed', null)
            );
    }

    private function record(string $kana, bool $confirmed, ?object $date = null): ServiceRecord
    {
        $resident = Resident::factory()->for($this->facility)->create(['name_kana' => $kana]);

        return ServiceRecord::factory()->for($resident)->create([
            'service_date' => $date ?? today(),
            'recorded_by' => $this->manager->id,
            'confirmed_at' => $confirmed ? now() : null,
        ]);
    }
}
