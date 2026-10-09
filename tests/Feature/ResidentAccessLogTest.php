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
use Tests\TestCase;

/**
 * 閲覧履歴（要件定義 F-19、9.3.2節 施策5）。
 *
 * 情報が漏れたときに「誰の情報が見られたか」を特定するための記録。
 * 残すべきときに残らないことと、残さなくてよいときに積み上がって
 * 本当に知りたい行が埋もれることの、両方を確かめる。
 */
class ResidentAccessLogTest extends TestCase
{
    use RefreshDatabase;

    private Facility $facility;

    private User $manager;

    private User $staff;

    private Resident $resident;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facility = Facility::factory()->create();
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

    private function record(): ServiceRecord
    {
        return $this->resident->serviceRecords()->create([
            'recorded_by' => $this->staff->id,
            'service_date' => today(),
            'attendance_status' => 'attended',
        ]);
    }
}
