<?php

namespace Tests\Feature;

use App\Enums\ResidentAccessAction;
use App\Enums\UserRole;
use App\Models\Facility;
use App\Models\Resident;
use App\Models\ResidentAccessLog;
use App\Models\ServiceRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * 閲覧履歴（要件定義 F-19、9.3.2節 施策5）。
 *
 * 情報が漏れたときに「誰の情報が見られたか」を特定するための記録。
 * 残すべきときに残らないことと、残さなくてよいときに積み上がって
 * 本当に知りたい行が埋もれることの、両方を確かめる。
 * 後半は、管理者がその記録を画面で確かめるところ。
 */
class ResidentAccessLogTest extends TestCase
{
    use RefreshDatabase;

    private Facility $facility;

    private User $admin;

    private User $manager;

    private User $staff;

    private Resident $resident;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facility = Facility::factory()->create();
        $this->admin = User::factory()->create([
            'facility_id' => $this->facility->id,
            'role' => UserRole::Admin,
        ]);
        $this->manager = User::factory()->create([
            'facility_id' => $this->facility->id,
            'role' => UserRole::Manager,
        ]);
        $this->staff = User::factory()->create([
            'facility_id' => $this->facility->id,
            'role' => UserRole::Staff,
        ]);
        $this->resident = Resident::factory()->for($this->facility)->create();
    }

    // ---------------------------------------------------------------
    // 1人ずつ開く画面では残す
    // ---------------------------------------------------------------

    public function test_利用者詳細を開くと誰がいつどのご利用者を見たかが残る(): void
    {
        $this->actingAs($this->staff)
            ->get(route('residents.show', $this->resident))
            ->assertOk();

        $log = ResidentAccessLog::query()->sole();

        $this->assertSame($this->staff->id, $log->user_id);
        $this->assertSame($this->resident->id, $log->resident_id);
        $this->assertSame(ResidentAccessAction::ViewResident, $log->action);
        $this->assertSame('127.0.0.1', $log->ip_address);
        $this->assertNotNull($log->created_at);
    }

    public function test_利用者情報の編集画面を開くと残る(): void
    {
        // 編集画面には住所・連絡先・既往歴がそのまま並ぶ
        $this->actingAs($this->manager)
            ->get(route('residents.edit', $this->resident))
            ->assertOk();

        $log = ResidentAccessLog::query()->sole();

        $this->assertSame($this->resident->id, $log->resident_id);
        $this->assertSame(ResidentAccessAction::EditResident, $log->action);
    }

    public function test_記録の画面を開くとその記録のご利用者として残る(): void
    {
        $record = $this->record();

        $this->actingAs($this->staff)
            ->get(route('records.edit', $record))
            ->assertOk();

        $log = ResidentAccessLog::query()->sole();

        $this->assertSame($this->resident->id, $log->resident_id);
        $this->assertSame(ResidentAccessAction::ViewRecord, $log->action);
    }

    public function test_連絡帳の印刷画面を開くと残る(): void
    {
        // 紙になってご家族へ渡る。誰が出したのかを後から辿れるようにする
        $record = $this->record();

        $this->actingAs($this->staff)
            ->get(route('records.family-report', $record))
            ->assertOk();

        $log = ResidentAccessLog::query()->sole();

        $this->assertSame($this->resident->id, $log->resident_id);
        $this->assertSame(ResidentAccessAction::PrintFamilyReport, $log->action);
    }

    // ---------------------------------------------------------------
    // 残さない
    // ---------------------------------------------------------------

    public function test_権限で弾かれた画面では残さない(): void
    {
        // 見ていないものを「見た」と残すと、漏えいの範囲を実際より広く見積もる
        $outsider = Resident::factory()->for(Facility::factory()->create())->create();

        $this->actingAs($this->staff)
            ->get(route('residents.show', $outsider))
            ->assertForbidden();

        $this->actingAs($this->staff)
            ->get(route('residents.edit', $this->resident))
            ->assertForbidden();

        $this->assertSame(0, ResidentAccessLog::query()->count());
    }

    public function test_結果を待つあいだの部分的な読み直しでは残さない(): void
    {
        // AI処理の実行中、画面は数秒ごとに一部の項目だけを読み直す。
        // これを数えると、1回開いただけで数十行が積まれる。
        $page = $this->actingAs($this->manager)
            ->get(route('residents.show', $this->resident))
            ->viewData('page');

        $this->actingAs($this->manager)
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Version' => $page['version'],
                'X-Inertia-Partial-Component' => 'residents/show',
                'X-Inertia-Partial-Data' => 'llmJobs',
            ])
            ->get(route('residents.show', $this->resident))
            ->assertOk();

        $this->assertSame(1, ResidentAccessLog::query()->count());
    }

    public function test_一覧画面では残さない(): void
    {
        // 一覧は開くたびに全員分の行ができ、誰を詳しく見たのかが埋もれる
        $this->actingAs($this->staff)
            ->get(route('residents.index'))
            ->assertOk();

        $this->assertSame(0, ResidentAccessLog::query()->count());
    }

    // ---------------------------------------------------------------
    // 管理者が画面で確かめる
    // ---------------------------------------------------------------

    public function test_管理者は誰がいつどのご利用者を見たかを画面で確かめられる(): void
    {
        $this->accessLog($this->staff, $this->resident, ResidentAccessAction::ViewRecord);

        $this->actingAs($this->admin)->get(route('access-logs.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('access-logs/index')
                ->has('logs.data', 1)
                ->where('logs.data.0.viewer', $this->staff->name)
                ->where('logs.data.0.viewerId', $this->staff->id)
                ->where('logs.data.0.residentName', "{$this->resident->name} 様")
                ->where('logs.data.0.residentId', $this->resident->id)
                // 列名のまま出しても、どの画面のことか分からない
                ->where('logs.data.0.actionLabel', '記録')
                ->where('logs.data.0.ipAddress', '127.0.0.1')
                ->where('filter.resident', null)
                ->where('filter.user', null)
            );
    }

    public function test_管理者のほかは閲覧履歴を開けない(): void
    {
        // 誰が誰を見たかは運用を預かる側の情報で、日々の介護業務では開かない
        $this->actingAs($this->manager)->get(route('access-logs.index'))->assertForbidden();
        $this->actingAs($this->staff)->get(route('access-logs.index'))->assertForbidden();
    }

    public function test_未ログインでは開けない(): void
    {
        $this->get(route('access-logs.index'))->assertRedirect();
    }

    public function test_他の事業所のご利用者の閲覧履歴は出ない(): void
    {
        $outsider = Resident::factory()->for(Facility::factory()->create())->create();
        $this->accessLog(null, $outsider, ResidentAccessAction::ViewResident);
        $this->accessLog($this->staff, $this->resident, ResidentAccessAction::ViewResident);

        $this->actingAs($this->admin)->get(route('access-logs.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('logs.data', 1)
                ->where('logs.data.0.residentId', $this->resident->id)
                // 件数も自分の事業所の分だけを数える
                ->where('logs.total', 1)
            );
    }

    public function test_新しい順に並ぶ(): void
    {
        $this->travelTo(now()->subHour());
        $older = $this->accessLog($this->staff, $this->resident, ResidentAccessAction::ViewResident);
        $this->travelBack();
        $newer = $this->accessLog($this->manager, $this->resident, ResidentAccessAction::EditResident);

        $this->actingAs($this->admin)->get(route('access-logs.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('logs.data.0.id', $newer->id)
                ->where('logs.data.1.id', $older->id)
            );
    }

    public function test_ご利用者で絞り込める(): void
    {
        // 漏えいのあとに最初に問われる「この方の情報を誰が見たか」
        $other = Resident::factory()->for($this->facility)->create();
        $this->accessLog($this->staff, $this->resident, ResidentAccessAction::ViewResident);
        $this->accessLog($this->staff, $other, ResidentAccessAction::ViewResident);

        $this->actingAs($this->admin)->get(route('access-logs.index', ['resident' => $other->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('logs.data', 1)
                ->where('logs.data.0.residentId', $other->id)
                ->where('filter.resident.id', $other->id)
                ->where('filter.resident.name', "{$other->name} 様")
            );
    }

    public function test_職員で絞り込める(): void
    {
        // 内部の不正を疑うときに問われる「この職員が誰の情報を見たか」
        $this->accessLog($this->staff, $this->resident, ResidentAccessAction::ViewResident);
        $this->accessLog($this->manager, $this->resident, ResidentAccessAction::EditResident);

        $this->actingAs($this->admin)->get(route('access-logs.index', ['user' => $this->manager->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('logs.data', 1)
                ->where('logs.data.0.viewerId', $this->manager->id)
                ->where('filter.user.id', $this->manager->id)
                ->where('filter.user.name', $this->manager->name)
            );
    }

    public function test_他の事業所のご利用者や職員では絞り込めない(): void
    {
        // 絞り込みの値から、他の事業所の方の名前が見えてはいけない
        $otherFacility = Facility::factory()->create();
        $outsider = Resident::factory()->for($otherFacility)->create();
        $outsiderStaff = User::factory()->create(['facility_id' => $otherFacility->id]);

        $this->actingAs($this->admin)
            ->get(route('access-logs.index', ['resident' => $outsider->id]))
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->get(route('access-logs.index', ['user' => $outsiderStaff->id]))
            ->assertNotFound();
    }

    public function test_利用終了で論理削除されたご利用者の分も出る(): void
    {
        // 保存期間のあいだは、利用を終えた方の情報も調査の対象になる
        $this->accessLog($this->staff, $this->resident, ResidentAccessAction::ViewResident);
        $this->resident->delete();

        $this->actingAs($this->admin)->get(route('access-logs.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('logs.data', 1)
                ->where('logs.data.0.residentName', "{$this->resident->name} 様")
            );
    }

    // ---------------------------------------------------------------

    private function accessLog(?User $viewer, Resident $resident, ResidentAccessAction $action): ResidentAccessLog
    {
        return ResidentAccessLog::create([
            'user_id' => $viewer?->id,
            'resident_id' => $resident->id,
            'action' => $action,
            'ip_address' => '127.0.0.1',
        ]);
    }

    private function record(): ServiceRecord
    {
        return $this->resident->serviceRecords()->create([
            'recorded_by' => $this->staff->id,
            'service_date' => today(),
            'attendance_status' => 'attended',
        ]);
    }
}
