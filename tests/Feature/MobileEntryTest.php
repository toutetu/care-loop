<?php

namespace Tests\Feature;

use App\Enums\RiskSeverity;
use App\Enums\UserRole;
use App\Enums\VerbalContactStatus;
use App\Models\Facility;
use App\Models\Resident;
use App\Models\RiskAssessment;
use App\Models\RiskFinding;
use App\Models\ServiceRecord;
use App\Models\User;
use App\Models\VerbalContactTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * スマートフォンから使うときの入口。
 *
 * ログイン直後の行き先、下のバーの「お知らせ」と「連絡」、
 * 一覧から直接メモを残すために渡す値を確かめる。
 */
class MobileEntryTest extends TestCase
{
    use RefreshDatabase;

    private Facility $facility;

    private User $staff;

    private Resident $resident;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facility = Facility::factory()->create();
        $this->staff = User::factory()->create([
            'facility_id' => $this->facility->id,
            'role' => UserRole::Staff,
        ]);
        $this->resident = Resident::factory()->for($this->facility)->create();
    }

    // ---------------------------------------------------------------
    // ログイン直後の行き先
    // ---------------------------------------------------------------

    public function test_スマートフォンでは記録の一覧から始まる(): void
    {
        // ダッシュボードを経由させると、メモを残すまでに1回多く押させる
        $this->actingAs($this->staff)
            ->withUnencryptedCookie('device', 'phone')
            ->get('/start')
            ->assertRedirect(route('records.index'));
    }

    public function test_p_cとタブレットではダッシュボードから始まる(): void
    {
        foreach (['desktop', 'tablet'] as $device) {
            $this->actingAs($this->staff)
                ->withUnencryptedCookie('device', $device)
                ->get('/start')
                ->assertRedirect(route('dashboard'));
        }
    }

    public function test_端末が分からなければダッシュボードから始まる(): void
    {
        // 画面側の処理が動く前に来た場合。これまでどおりの行き先に戻す。
        $this->actingAs($this->staff)
            ->get('/start')
            ->assertRedirect(route('dashboard'));
    }

    // ---------------------------------------------------------------
    // お知らせ
    // ---------------------------------------------------------------

    public function test_お知らせにはダッシュボードと同じものが並ぶ(): void
    {
        $this->urgentRisk($this->resident);
        $this->pendingContact($this->resident);

        // 済んだ連絡は出さない
        VerbalContactTask::factory()->create([
            'resident_id' => $this->resident->id,
            'status' => VerbalContactStatus::Completed,
        ]);

        $this->actingAs($this->staff)->get('/notices')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('notices/index')
                ->has('risks', 1)
                ->has('verbalContacts', 1)
            );
    }

    public function test_下のバーの件数をどの画面にも渡す(): void
    {
        $this->urgentRisk($this->resident);
        $this->pendingContact($this->resident);

        // 他の事業所の分は数えない
        $other = Resident::factory()->for(Facility::factory()->create())->create();
        $this->urgentRisk($other);
        $this->pendingContact($other);

        $this->actingAs($this->staff)->get('/records')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('noticeCount', 2)
            );
    }

    public function test_連絡の画面を開ける(): void
    {
        $this->actingAs($this->staff)->get('/messages')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('messages/index')
            );
    }

    public function test_未ログインでは開けない(): void
    {
        foreach (['/start', '/notices', '/messages'] as $path) {
            $this->get($path)->assertRedirect(route('login'));
        }
    }

    // ---------------------------------------------------------------
    // 一覧から直接メモを残す
    // ---------------------------------------------------------------

    public function test_一覧にメモの件数を渡す(): void
    {
        // もう話して残したかが一覧で分かれば、同じことを二度残さずに済む
        $record = ServiceRecord::factory()->for($this->resident)->create([
            'service_date' => today(),
            'recorded_by' => $this->staff->id,
        ]);
        $record->notes()->create([
            'recorded_by' => $this->staff->id,
            'body' => '昼食のときに少しむせこみがあった',
            'input_method' => 'voice',
        ]);

        $this->actingAs($this->staff)->get('/records')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('records.0.notesCount', 1)
            );
    }

    public function test_一覧から残したメモは一覧へ戻る(): void
    {
        $record = ServiceRecord::factory()->for($this->resident)->create([
            'service_date' => today(),
            'recorded_by' => $this->staff->id,
        ]);

        $this->actingAs($this->staff)
            ->from('/records')
            ->post("/records/{$record->id}/notes", [
                'body' => '午後の体操に参加された',
                'input_method' => 'voice',
            ])
            ->assertRedirect('/records');

        $this->assertSame(1, $record->notes()->count());
    }

    private function urgentRisk(Resident $resident): void
    {
        $assessment = RiskAssessment::factory()->for($resident)->create(['reviewed_at' => null]);
        RiskFinding::factory()->create([
            'risk_assessment_id' => $assessment->id,
            'severity' => RiskSeverity::High,
        ]);
    }

    private function pendingContact(Resident $resident): void
    {
        VerbalContactTask::factory()->create([
            'resident_id' => $resident->id,
            'status' => VerbalContactStatus::Pending,
        ]);
    }
}
