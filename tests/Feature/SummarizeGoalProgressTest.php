<?php

namespace Tests\Feature;

use App\Enums\ProgressStatus;
use App\Llm\Clients\FakeClient;
use App\Llm\LlmGateway;
use App\Llm\LlmRequestLogger;
use App\Llm\Prompts\GoalProgressPrompt;
use App\Llm\Support\PeriodStatistics;
use App\Llm\Support\ResponseValidator;
use App\Llm\UseCases\SummarizeGoalProgress;
use App\Models\CarePlan;
use App\Models\CarePlanGoal;
use App\Models\GoalProgressItem;
use App\Models\GoalProgressReport;
use App\Models\Resident;
use App\Models\ServiceRecord;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * F-LLM-01 目標進捗要約。
 *
 * 職務経歴書の「業務データを基にプロジェクト進捗の要約を行う機能」に対応する。
 * 介護ドメインでは、通所介護計画書の短期目標に対するモニタリングがそれにあたる。
 */
class SummarizeGoalProgressTest extends TestCase
{
    use RefreshDatabase;

    private FakeClient $fake;

    private SummarizeGoalProgress $useCase;

    private Resident $resident;

    private CarePlan $plan;

    /** @var Collection<int, CarePlanGoal> */
    private Collection $goals;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fake = new FakeClient;
        $this->useCase = new SummarizeGoalProgress(
            new LlmGateway($this->fake, new ResponseValidator, new LlmRequestLogger),
            new GoalProgressPrompt,
            new PeriodStatistics,
        );

        $this->resident = Resident::factory()->create(['name' => '佐藤 ハナ', 'name_kana' => 'サトウ ハナ']);
        $this->plan = CarePlan::factory()->for($this->resident)->create();

        $this->goals = CarePlanGoal::factory()->count(3)->for($this->plan)->create();
    }

    // ---------------------------------------------------------------
    // 評価の保存
    // ---------------------------------------------------------------

    public function test_目標ごとの評価が保存される(): void
    {
        $this->queueEvaluations([
            [$this->goals[0]->id, 'unchanged'],
            [$this->goals[1]->id, 'declining'],
            [$this->goals[2]->id, 'improving'],
        ]);

        $report = $this->summarize();

        $this->assertCount(3, $report->items);
        $this->assertSame(
            [ProgressStatus::Unchanged, ProgressStatus::Declining, ProgressStatus::Improving],
            $report->items->sortBy('care_plan_goal_id')->pluck('progress_status')->values()->all(),
        );
    }

    public function test_総括と提案と確信度が保存される(): void
    {
        $report = $this->summarize();

        $this->assertNotNull($report->overall_summary);
        $this->assertNotSame([], $report->next_actions);
        $this->assertSame('medium', $report->confidence);
    }

    public function test_職員が確認するまで未確認のまま残る(): void
    {
        $report = $this->summarize();

        $this->assertNull($report->reviewed_at);
    }

    // ---------------------------------------------------------------
    // 出力の健全性
    // ---------------------------------------------------------------

    public function test_計画書に存在しない目標idは捨てる(): void
    {
        // 存在しない目標への評価を保存すると、記録として意味をなさない
        $this->queueEvaluations([
            [$this->goals[0]->id, 'improving'],
            [999999, 'improving'],
        ]);

        $report = $this->summarize();

        $this->assertCount(3, $report->items, '計画書の3目標ぶんだけが残る');
        $this->assertSame(
            $this->goals->pluck('id')->sort()->values()->all(),
            $report->items->pluck('care_plan_goal_id')->sort()->values()->all(),
        );
    }

    public function test_評価されなかった目標は材料不足として残る(): void
    {
        // 一部の目標が抜け落ちたモニタリング記録は不完全である
        $this->queueEvaluations([
            [$this->goals[0]->id, 'improving'],
        ]);

        $report = $this->summarize();

        $notEvaluated = $report->items->where('care_plan_goal_id', '!=', $this->goals[0]->id);

        $this->assertCount(2, $notEvaluated);

        foreach ($notEvaluated as $item) {
            $this->assertSame(ProgressStatus::InsufficientData, $item->progress_status);
            $this->assertStringContainsString('手動で評価', (string) $item->comment);
        }
    }

    public function test_同じ目標が重複していても1件しか保存しない(): void
    {
        $this->queueEvaluations([
            [$this->goals[0]->id, 'improving'],
            [$this->goals[0]->id, 'declining'],
        ]);

        $report = $this->summarize();

        $this->assertCount(
            1,
            $report->items->where('care_plan_goal_id', $this->goals[0]->id),
        );
    }

    public function test_材料不足の評価は職員の確認を促す対象になる(): void
    {
        $this->queueEvaluations([]);

        $this->summarize();

        $needsAttention = GoalProgressItem::query()->needsAttention()->count();

        $this->assertSame(3, $needsAttention);
    }

    // ---------------------------------------------------------------
    // 送信内容
    // ---------------------------------------------------------------

    public function test_集計値を送って数え直させない(): void
    {
        ServiceRecord::factory()->for($this->resident)->create([
            'service_date' => '2026-08-04',
            'total_water_ml' => 1300,
        ]);
        ServiceRecord::factory()->for($this->resident)->create([
            'service_date' => '2026-08-06',
            'total_water_ml' => 900,
        ]);

        $this->summarize();

        $sent = (string) $this->fake->lastRequest()?->userMessage;

        $this->assertStringContainsString('集計値（算出済みです', $sent);
        $this->assertStringContainsString('水分摂取: 平均 1100ml', $sent);
        $this->assertStringContainsString('目標 1200ml 達成は 1回', $sent);
    }

    public function test_目標idを添えて送る(): void
    {
        $this->summarize();

        $sent = (string) $this->fake->lastRequest()?->userMessage;

        foreach ($this->goals as $goal) {
            $this->assertStringContainsString("goal_id {$goal->id}:", $sent);
        }
    }

    public function test_送信本文に実名が含まれない(): void
    {
        ServiceRecord::factory()->for($this->resident)->create([
            'service_date' => '2026-08-04',
            'record_text' => '佐藤ハナ様は入浴時に手すりを使用された。',
        ]);

        $this->summarize();

        $sent = (string) $this->fake->lastRequest()?->userMessage;

        $this->assertStringNotContainsString('佐藤', $sent);
        $this->assertStringContainsString('{{RESIDENT_1}}', $sent);
    }

    public function test_自由記述がなければ材料不足を選ぶよう伝える(): void
    {
        $this->summarize();

        $sent = (string) $this->fake->lastRequest()?->userMessage;

        $this->assertStringContainsString('insufficient_data としてください', $sent);
    }

    // ---------------------------------------------------------------

    private function summarize(): GoalProgressReport
    {
        return $this->useCase->handle(
            $this->resident->fresh(),
            $this->plan->fresh(),
            Carbon::parse('2026-08-01'),
            Carbon::parse('2026-08-31'),
        );
    }

    /**
     * @param  list<array{0: int, 1: string}>  $evaluations
     */
    private function queueEvaluations(array $evaluations): void
    {
        $this->fake->queueParsed([
            'overall_summary' => '期間を通じて大きな変化は見られませんでした。',
            'confidence' => 'medium',
            'next_actions' => ['水分摂取の声かけを増やす'],
            'goals' => array_map(
                static fn (array $e): array => [
                    'goal_id' => $e[0],
                    'progress_status' => $e[1],
                    'comment' => '評価コメント',
                    'evidence' => [
                        ['record_id' => null, 'date' => '2026-08-31', 'excerpt' => '集計値より'],
                    ],
                ],
                $evaluations,
            ),
        ]);
    }
}
