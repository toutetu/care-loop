<?php

namespace Tests\Feature;

use App\Enums\RiskSeverity;
use App\Enums\RiskSource;
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
 * ダッシュボード。朝礼と申し送りの場面で開く画面。
 */
class DashboardTest extends TestCase
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
        $this->resident = Resident::factory()->for($this->facility)->create(['name' => '佐藤 ハナ']);
    }

    // ---------------------------------------------------------------
    // 表示する日付
    // ---------------------------------------------------------------

    public function test_本日の記録があれば本日を表示する(): void
    {
        ServiceRecord::factory()->for($this->resident)->create([
            'service_date' => today(),
            'recorded_by' => $this->staff->id,
        ]);

        $this->actingAs($this->staff)->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('dashboard')
                ->where('day.isToday', true)
                ->where('counts.residents', 1)
            );
    }

    public function test_本日に記録がなければ直近の利用日へ下がる(): void
    {
        // 通所介護は日曜や祝日に営業しない事業所が多い。休業日に開いて
        // 一覧が空になると、壊れているのか休みなのか区別がつかない。
        ServiceRecord::factory()->for($this->resident)->create([
            'service_date' => today()->subDays(3),
            'recorded_by' => $this->staff->id,
        ]);

        $this->actingAs($this->staff)->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('day.isToday', false)
                ->where('day.date', today()->subDays(3)->toDateString())
                ->where('counts.residents', 1)
            );
    }

    // ---------------------------------------------------------------
    // 要対応のリスク
    // ---------------------------------------------------------------

    public function test_未確認で重要度の高い指摘だけが並ぶ(): void
    {
        $unreviewed = RiskAssessment::factory()->for($this->resident)->create(['reviewed_at' => null]);
        RiskFinding::factory()->create([
            'risk_assessment_id' => $unreviewed->id,
            'severity' => RiskSeverity::High,
            'title' => '1ヶ月で体重が 3.5% 減少',
        ]);

        // 重要度が中のものは出さない
        RiskFinding::factory()->create([
            'risk_assessment_id' => $unreviewed->id,
            'severity' => RiskSeverity::Medium,
            'title' => '中程度の指摘',
        ]);

        // 確認済みのものも出さない。既読が並び続けると新しい指摘が埋もれる。
        $reviewed = RiskAssessment::factory()->for($this->resident)->create([
            'reviewed_at' => now(),
            'reviewed_by' => $this->staff->id,
        ]);
        RiskFinding::factory()->create([
            'risk_assessment_id' => $reviewed->id,
            'severity' => RiskSeverity::High,
            'title' => '確認済みの指摘',
        ]);

        $this->actingAs($this->staff)->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('risks', 1)
                ->where('risks.0.title', '1ヶ月で体重が 3.5% 減少')
            );
    }

    public function test_検出元が区別できる形で渡る(): void
    {
        // ルールベースかLLMかを画面で区別できることが、この設計の要点にあたる
        $assessment = RiskAssessment::factory()->for($this->resident)->create(['reviewed_at' => null]);
        RiskFinding::factory()->llmDetected()->create([
            'risk_assessment_id' => $assessment->id,
            'severity' => RiskSeverity::High,
        ]);

        $this->actingAs($this->staff)->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('risks.0.source', RiskSource::LlmDetected->value)
                ->where('risks.0.isDeterministic', false)
            );
    }

    public function test_他の事業所の指摘は出さない(): void
    {
        $other = Resident::factory()->for(Facility::factory()->create())->create();
        $assessment = RiskAssessment::factory()->for($other)->create(['reviewed_at' => null]);
        RiskFinding::factory()->create([
            'risk_assessment_id' => $assessment->id,
            'severity' => RiskSeverity::High,
        ]);

        $this->actingAs($this->staff)->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('risks', 0));
    }

    // ---------------------------------------------------------------
    // 口頭連絡
    // ---------------------------------------------------------------

    public function test_未連絡の口頭連絡が並ぶ(): void
    {
        VerbalContactTask::factory()->create([
            'resident_id' => $this->resident->id,
            'topic' => '食事中のむせ込み',
            'status' => VerbalContactStatus::Pending,
        ]);

        VerbalContactTask::factory()->create([
            'resident_id' => $this->resident->id,
            'topic' => '連絡済みの件',
            'status' => VerbalContactStatus::Completed,
        ]);

        $this->actingAs($this->staff)->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('verbalContacts', 1)
                ->where('verbalContacts.0.topic', '食事中のむせ込み')
            );
    }

    // ---------------------------------------------------------------
    // 権限
    // ---------------------------------------------------------------

    public function test_一般職員には利用ログへの導線を出さない(): void
    {
        // 費用と失敗率は運営の情報であり、日々の介護業務には要らない
        $this->actingAs($this->staff)->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canViewLlmLogs', false));
    }

    public function test_管理者には利用ログへの導線を出す(): void
    {
        $admin = User::factory()->create([
            'facility_id' => $this->facility->id,
            'role' => UserRole::Admin,
        ]);

        $this->actingAs($admin)->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canViewLlmLogs', true));
    }

    public function test_未ログインでは開けない(): void
    {
        $this->get('/dashboard')->assertRedirect();
    }
}
