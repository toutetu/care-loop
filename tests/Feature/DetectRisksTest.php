<?php

namespace Tests\Feature;

use App\Enums\RiskCategory;
use App\Enums\RiskSource;
use App\Llm\Clients\FakeClient;
use App\Llm\LlmGateway;
use App\Llm\LlmRequestLogger;
use App\Llm\Prompts\RiskDetectionPrompt;
use App\Llm\Support\ResponseValidator;
use App\Llm\Support\RiskIndicatorCalculator;
use App\Llm\UseCases\DetectRisks;
use App\Models\Resident;
use App\Models\RiskAssessment;
use App\Models\ServiceRecord;
use App\Models\WeightRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * F-LLM-02 リスク兆候の抽出（ハイブリッド設計）。
 *
 * 数値で判定できるものはルールベースで決定的に算出し、
 * 数値に現れない質的変化だけをLLMに求める。
 * 両者を1つの評価にまとめ、検出元を区別して保存する。
 */
class DetectRisksTest extends TestCase
{
    use RefreshDatabase;

    private FakeClient $fake;

    private DetectRisks $useCase;

    private Resident $resident;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fake = new FakeClient;
        $this->useCase = new DetectRisks(
            new LlmGateway($this->fake, new ResponseValidator, new LlmRequestLogger),
            new RiskDetectionPrompt,
            new RiskIndicatorCalculator,
        );

        $this->resident = Resident::factory()->create(['name' => '佐藤 ハナ', 'name_kana' => 'サトウ ハナ']);
    }

    // ---------------------------------------------------------------
    // 検出元の区別
    // ---------------------------------------------------------------

    public function test_ルールベースの指摘とllmの指摘が両方保存される(): void
    {
        $this->weightLoss();

        $assessment = $this->detect();

        $sources = $assessment->findings->pluck('source')->all();

        $this->assertContains(RiskSource::Both, $sources, '低栄養はルールとLLMの両方が挙げている');
        $this->assertContains(RiskSource::LlmDetected, $sources, '転倒はLLMのみが挙げている');
    }

    public function test_ルールベースだけが挙げた指摘はrule_basedになる(): void
    {
        // LLM側が挙げない種別（脱水）をルールベースで作る
        $this->lowWaterStreak();

        $assessment = $this->detect();

        $dehydration = $assessment->findings->firstWhere('category', RiskCategory::Dehydration);

        $this->assertNotNull($dehydration);
        $this->assertSame(RiskSource::RuleBased, $dehydration->source);
    }

    public function test_重複した種別はルールベース側にまとめllmの重複は保存しない(): void
    {
        // ルールベースのほうが具体的な数値を根拠に持つため、そちらを残す
        $this->weightLoss();

        $assessment = $this->detect();

        $malnutrition = $assessment->findings->where('category', RiskCategory::Malnutrition);

        $this->assertCount(1, $malnutrition, '同じ種別を二重に保存しない');
        $this->assertSame(RiskSource::Both, $malnutrition->first()->source);
        $this->assertStringContainsString('3.5', $malnutrition->first()->title, 'ルールベース側の具体的な数値が残る');
    }

    public function test_すべての指摘に根拠が添えられている(): void
    {
        $this->weightLoss();

        $assessment = $this->detect();

        foreach ($assessment->findings as $finding) {
            $this->assertTrue($finding->hasEvidence(), "{$finding->title} に根拠がない");
        }
    }

    // ---------------------------------------------------------------
    // 送信内容
    // ---------------------------------------------------------------

    public function test_算出済みの指標を送信して重複指摘を防ぐ(): void
    {
        $this->weightLoss();

        $this->detect();

        $sent = (string) $this->fake->lastRequest()?->userMessage;

        $this->assertStringContainsString('すでに算出済みの指標', $sent);
        $this->assertStringContainsString('体重が 3.5% 減少', $sent);
    }

    public function test_記録には記録idを添えて送る(): void
    {
        // LLMに根拠として引用させるために必要
        $record = ServiceRecord::factory()->for($this->resident)->create([
            'service_date' => '2026-09-02',
            'record_text' => '送迎車の乗降時、ステップでふらつきが見られた。',
        ]);

        $this->detect();

        $sent = (string) $this->fake->lastRequest()?->userMessage;

        $this->assertStringContainsString("[記録 #{$record->id}]", $sent);
        $this->assertStringContainsString('ふらつき', $sent);
    }

    public function test_送信本文に実名が含まれない(): void
    {
        ServiceRecord::factory()->for($this->resident)->create([
            'service_date' => '2026-09-02',
            'record_text' => '佐藤ハナ様は入浴時にふらつきが見られた。',
        ]);

        $this->detect();

        $sent = (string) $this->fake->lastRequest()?->userMessage;

        $this->assertStringNotContainsString('佐藤', $sent);
        $this->assertStringContainsString('{{RESIDENT_1}}', $sent);
    }

    public function test_自由記述がなければ確信度を下げるよう伝える(): void
    {
        $this->detect();

        $sent = (string) $this->fake->lastRequest()?->userMessage;

        $this->assertStringContainsString('confidence は low', $sent);
    }

    // ---------------------------------------------------------------
    // 評価そのもの
    // ---------------------------------------------------------------

    public function test_確信度が保存される(): void
    {
        $assessment = $this->detect();

        $this->assertSame('medium', $assessment->confidence);
    }

    public function test_職員が確認するまで未確認のまま残る(): void
    {
        $assessment = $this->detect();

        $this->assertFalse($assessment->isReviewed());
        $this->assertNull($assessment->reviewed_at);
    }

    public function test_どちらも指摘がなければリスクなしと記録される(): void
    {
        $this->fake->queueParsed([
            'no_risk_detected' => true,
            'confidence' => 'high',
            'risks' => [],
        ]);

        $assessment = $this->detect();

        $this->assertTrue($assessment->no_risk_detected);
        $this->assertCount(0, $assessment->findings);
    }

    // ---------------------------------------------------------------

    private function detect(): RiskAssessment
    {
        return $this->useCase->handle(
            $this->resident->fresh(),
            Carbon::parse('2026-06-01'),
            Carbon::parse('2026-09-30'),
        );
    }

    private function weightLoss(): void
    {
        WeightRecord::factory()->for($this->resident)->create(['measured_on' => '2026-08-01', 'weight_kg' => 42.6]);
        WeightRecord::factory()->for($this->resident)->create(['measured_on' => '2026-09-01', 'weight_kg' => 41.1]);
    }

    private function lowWaterStreak(): void
    {
        foreach ([['2026-09-04', 980], ['2026-09-06', 940], ['2026-09-09', 910]] as [$date, $water]) {
            ServiceRecord::factory()->for($this->resident)->create([
                'service_date' => $date,
                'total_water_ml' => $water,
                'record_text' => null,
            ]);
        }
    }
}
