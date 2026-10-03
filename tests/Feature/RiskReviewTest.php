<?php

namespace Tests\Feature;

use App\Enums\RiskSeverity;
use App\Enums\UserRole;
use App\Models\Facility;
use App\Models\Resident;
use App\Models\RiskAssessment;
use App\Models\RiskFinding;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * リスク兆候の抽出結果を「確認済み」にする。
 *
 * 確認すると、お知らせとダッシュボードから外れ、誰がいつ確かめたかが残ること。
 */
class RiskReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_職員が確認済みにするとお知らせから外れ確認した人が残る(): void
    {
        $facility = Facility::factory()->create();
        $staff = User::factory()->create(['facility_id' => $facility->id, 'role' => UserRole::Staff]);
        $resident = Resident::factory()->for($facility)->create();
        $assessment = RiskAssessment::factory()->for($resident)->create(['reviewed_at' => null]);
        RiskFinding::factory()->create(['risk_assessment_id' => $assessment->id, 'severity' => RiskSeverity::High]);

        $this->actingAs($staff)->get('/notices')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('risks', 1));

        $this->actingAs($staff)->post("/risk-assessments/{$assessment->id}/review")->assertRedirect();

        $assessment->refresh();
        $this->assertSame($staff->id, $assessment->reviewed_by);
        $this->assertNotNull($assessment->reviewed_at);

        $this->actingAs($staff)->get('/notices')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('risks', 0));

        $this->actingAs($staff)->get("/residents/{$resident->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('riskAssessment.isReviewed', true)
                ->where('riskAssessment.reviewedBy', $staff->name)
            );
    }

    public function test_二度押しても最初に確認した人が残る(): void
    {
        $facility = Facility::factory()->create();
        $first = User::factory()->create(['facility_id' => $facility->id]);
        $second = User::factory()->create(['facility_id' => $facility->id]);
        $assessment = RiskAssessment::factory()->for(Resident::factory()->for($facility))->create(['reviewed_at' => null]);

        $this->actingAs($first)->post("/risk-assessments/{$assessment->id}/review");
        $this->actingAs($second)->post("/risk-assessments/{$assessment->id}/review");

        $this->assertSame($first->id, $assessment->refresh()->reviewed_by);
    }

    public function test_他の事業所の抽出結果は確認できない(): void
    {
        $staff = User::factory()->create(['facility_id' => Facility::factory()->create()->id]);
        $assessment = RiskAssessment::factory()
            ->for(Resident::factory()->for(Facility::factory()))
            ->create(['reviewed_at' => null]);

        $this->actingAs($staff)->post("/risk-assessments/{$assessment->id}/review")->assertForbidden();
        $this->assertNull($assessment->refresh()->reviewed_at);
    }
}
