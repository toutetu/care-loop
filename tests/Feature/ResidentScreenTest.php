<?php

namespace Tests\Feature;

use App\Enums\ProgressStatus;
use App\Enums\RiskSeverity;
use App\Enums\RiskSource;
use App\Enums\UserRole;
use App\Models\CarePlan;
use App\Models\CarePlanGoal;
use App\Models\Facility;
use App\Models\GoalProgressItem;
use App\Models\GoalProgressReport;
use App\Models\Resident;
use App\Models\RiskAssessment;
use App\Models\RiskFinding;
use App\Models\ServiceRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * ご利用者の一覧と詳細。
 *
 * 詳細画面はAIの出力を職員が確認する場所でもある。
 * 根拠を辿れない出力を画面に出さないことが、この機能の前提にあたる。
 */
class ResidentScreenTest extends TestCase
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

        $this->resident = Resident::factory()->for($this->facility)->create([
            'name' => '佐藤 ハナ',
            'name_kana' => 'サトウ ハナ',
        ]);
    }

    // ---------------------------------------------------------------
    // 一覧
    // ---------------------------------------------------------------

    public function test_自分の事業所のご利用者だけが並ぶ(): void
    {
        Resident::factory()->for(Facility::factory()->create())->create(['name' => '他事業所 太郎']);

        $this->actingAs($this->staff)->get('/residents')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('residents/index')
                ->has('residents', 1)
                ->where('residents.0.name', '佐藤 ハナ')
            );
    }

    public function test_未確認の重要な指摘の件数が一覧に出る(): void
    {
        // 詳細を開かないと分からない状態では、見落としが起きる
        $assessment = RiskAssessment::factory()->for($this->resident)->create(['reviewed_at' => null]);
        RiskFinding::factory()->create([
            'risk_assessment_id' => $assessment->id,
            'severity' => RiskSeverity::High,
        ]);

        $this->actingAs($this->staff)->get('/residents')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('residents.0.unreviewedRiskCount', 1)
            );
    }

    // ---------------------------------------------------------------
    // 詳細：リスク兆候
    // ---------------------------------------------------------------

    public function test_指摘は検出元と根拠つきで渡る(): void
    {
        $record = ServiceRecord::factory()->for($this->resident)->create([
            'recorded_by' => $this->staff->id,
        ]);

        $assessment = RiskAssessment::factory()->for($this->resident)->create();
        RiskFinding::factory()->llmDetected()->create([
            'risk_assessment_id' => $assessment->id,
            'evidence' => [
                ['record_id' => $record->id, 'date' => '2026-09-11', 'excerpt' => 'ふらつきが見られた'],
            ],
        ]);

        $this->actingAs($this->staff)->get($this->showUrl())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('residents/show')
                ->where('riskAssessment.findings.0.source', RiskSource::LlmDetected->value)
                ->where('riskAssessment.findings.0.isDeterministic', false)
                ->where('riskAssessment.findings.0.evidence.0.recordId', $record->id)
            );
    }

    public function test_重要度の高い指摘が先に並ぶ(): void
    {
        $assessment = RiskAssessment::factory()->for($this->resident)->create();

        RiskFinding::factory()->create([
            'risk_assessment_id' => $assessment->id,
            'severity' => RiskSeverity::Low,
            'title' => '軽微な指摘',
        ]);
        RiskFinding::factory()->create([
            'risk_assessment_id' => $assessment->id,
            'severity' => RiskSeverity::High,
            'title' => '重要な指摘',
        ]);

        $this->actingAs($this->staff)->get($this->showUrl())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('riskAssessment.findings.0.title', '重要な指摘')
            );
    }

    public function test_根拠のない指摘も隠さずに渡す(): void
    {
        // 根拠のない指摘は本来あってはならない。見た目を整えるために
        // 隠すのではなく、確認できない指摘であることを画面に出す。
        $assessment = RiskAssessment::factory()->for($this->resident)->create();
        RiskFinding::factory()->create([
            'risk_assessment_id' => $assessment->id,
            'evidence' => [],
        ]);

        $this->actingAs($this->staff)->get($this->showUrl())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('riskAssessment.findings', 1)
                ->has('riskAssessment.findings.0.evidence', 0)
            );
    }

    // ---------------------------------------------------------------
    // 詳細：目標進捗
    // ---------------------------------------------------------------

    public function test_材料不足の評価は要確認として渡る(): void
    {
        // 記録が足りない目標に無理な評価をさせないことがこの機能の要点。
        // その状態こそ職員が見るべきものなので、画面で目立たせる。
        $plan = CarePlan::factory()->for($this->resident)->create();
        $goal = CarePlanGoal::factory()->for($plan)->create(['goal_text' => '水分を1,200ml摂取する']);

        $report = GoalProgressReport::factory()->for($this->resident)->create([
            'care_plan_id' => $plan->id,
        ]);

        GoalProgressItem::factory()->create([
            'goal_progress_report_id' => $report->id,
            'care_plan_goal_id' => $goal->id,
            'progress_status' => ProgressStatus::InsufficientData,
        ]);

        $this->actingAs($this->staff)->get($this->showUrl())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('goalProgress.items.0.status', ProgressStatus::InsufficientData->value)
                ->where('goalProgress.items.0.needsAttention', true)
                ->where('goalProgress.items.0.goalText', '水分を1,200ml摂取する')
            );
    }

    public function test_確信度が低い要約は警告できる形で渡る(): void
    {
        $plan = CarePlan::factory()->for($this->resident)->create();
        GoalProgressReport::factory()->for($this->resident)->create([
            'care_plan_id' => $plan->id,
            'confidence' => 'low',
        ]);

        $this->actingAs($this->staff)->get($this->showUrl())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('goalProgress.isLowConfidence', true)
            );
    }

    // ---------------------------------------------------------------
    // 権限
    // ---------------------------------------------------------------

    public function test_別の事業所のご利用者は開けない(): void
    {
        // 氏名・保険者番号・既往歴を含む。所属していない事業所の方を
        // 見られてよい理由がない。
        $outsider = User::factory()->create([
            'facility_id' => Facility::factory()->create()->id,
        ]);

        $this->actingAs($outsider)->get($this->showUrl())->assertForbidden();
    }

    public function test_未ログインでは開けない(): void
    {
        $this->get('/residents')->assertRedirect();
        $this->get($this->showUrl())->assertRedirect();
    }

    // ---------------------------------------------------------------

    private function showUrl(): string
    {
        return route('residents.show', $this->resident);
    }
}
