<?php

namespace Tests\Feature;

use App\Enums\RiskCategory;
use App\Enums\RiskSeverity;
use App\Llm\Data\RiskIndicator;
use App\Llm\Support\RiskIndicatorCalculator;
use App\Models\IncidentReport;
use App\Models\MealRecord;
use App\Models\Resident;
use App\Models\ServiceRecord;
use App\Models\VitalSign;
use App\Models\WeightRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 数値で判定できるリスクの算出。LLMは一切使わない。
 *
 * 【なぜここをテストで固定するのか】
 * この層の価値は「同じ入力なら必ず同じ結果になる」ことにある。
 * 体重減少率をLLMに計算させれば、結果が揺れ、なぜその数値になったのかも
 * 検証できなくなる。テストで再現性を保証できること自体が、
 * ルールベースを選んだ理由そのものである。
 */
class RiskIndicatorCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private RiskIndicatorCalculator $calculator;

    private Resident $resident;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new RiskIndicatorCalculator;
        $this->resident = Resident::factory()->create();
    }

    // ---------------------------------------------------------------
    // 体重減少
    // ---------------------------------------------------------------

    public function test_3パーセント以上の体重減少を高リスクとして検出する(): void
    {
        $this->weight('2026-08-01', 42.6);
        $this->weight('2026-09-01', 41.1);   // 減少率 3.52%

        $indicator = $this->only(RiskCategory::Malnutrition);

        $this->assertSame(RiskSeverity::High, $indicator->severity);
        $this->assertStringContainsString('3.5', $indicator->title);
        $this->assertNotSame([], $indicator->evidence, '根拠を必ず添える');
    }

    public function test_3パーセント未満の減少は検出しない(): void
    {
        $this->weight('2026-08-01', 42.6);
        $this->weight('2026-09-01', 41.7);   // 減少率 2.1%

        $this->assertSame([], $this->calculate());
    }

    public function test_比較対象がなければ検出しない(): void
    {
        // 判定できないことを「異常なし」と扱わない
        $this->weight('2026-09-01', 41.1);

        $this->assertSame([], $this->calculate());
    }

    public function test_しきい値は設定ファイルから読まれる(): void
    {
        // 事業所ごとの運用差に対応できるよう、コードに数値を埋め込まない
        $this->weight('2026-08-01', 42.6);
        $this->weight('2026-09-01', 41.7);   // 2.1%

        $this->assertSame([], $this->calculate());

        config(['careloop.risk_thresholds.weight_loss_rate' => 0.02]);

        $this->assertCount(1, $this->calculate());
    }

    // ---------------------------------------------------------------
    // 水分摂取
    // ---------------------------------------------------------------

    public function test_目標未達が3日連続で脱水リスクを検出する(): void
    {
        $this->record('2026-09-02', water: 1250);
        $this->record('2026-09-04', water: 980);
        $this->record('2026-09-06', water: 940);
        $this->record('2026-09-09', water: 910);

        $indicator = $this->only(RiskCategory::Dehydration);

        $this->assertStringContainsString('3日連続', $indicator->title);
        $this->assertCount(3, $indicator->evidence);
    }

    public function test_2日で止まっていれば検出しない(): void
    {
        $this->record('2026-09-04', water: 980);
        $this->record('2026-09-06', water: 940);

        $this->assertSame([], $this->calculate());
    }

    public function test_直近で目標を達成していれば連続は途切れる(): void
    {
        // 過去に落ち込んでいても、今は戻っているなら警告しない
        $this->record('2026-09-02', water: 900);
        $this->record('2026-09-04', water: 900);
        $this->record('2026-09-06', water: 900);
        $this->record('2026-09-09', water: 1300);

        $this->assertSame([], $this->calculate());
    }

    public function test_未計測の日は連続の判定に含めない(): void
    {
        // 測っていないことと、少なかったことは違う
        $this->record('2026-09-02', water: 980);
        $this->record('2026-09-04', water: null);
        $this->record('2026-09-06', water: 940);
        $this->record('2026-09-09', water: 910);

        $indicator = $this->only(RiskCategory::Dehydration);

        $this->assertCount(3, $indicator->evidence);
    }

    // ---------------------------------------------------------------
    // 食事摂取量
    // ---------------------------------------------------------------

    public function test_摂取量の低下が週3回以上で検出する(): void
    {
        foreach (['2026-09-01', '2026-09-03', '2026-09-05'] as $date) {
            $record = $this->record($date, water: 1300);
            MealRecord::factory()->for($record)->lowIntake()->create(['meal_type' => 'lunch']);
        }

        $record = $this->record('2026-09-08', water: 1300);
        MealRecord::factory()->for($record)->create(['meal_type' => 'lunch', 'staple_rate' => 100, 'side_rate' => 100]);

        $indicator = $this->only(RiskCategory::Malnutrition);

        $this->assertSame(RiskSeverity::Medium, $indicator->severity);
        $this->assertCount(3, $indicator->evidence);
    }

    public function test_2回までなら検出しない(): void
    {
        foreach (['2026-09-01', '2026-09-03'] as $date) {
            $record = $this->record($date, water: 1300);
            MealRecord::factory()->for($record)->lowIntake()->create(['meal_type' => 'lunch']);
        }

        $this->assertSame([], $this->calculate());
    }

    // ---------------------------------------------------------------
    // バイタル
    // ---------------------------------------------------------------

    public function test_発熱を検出する(): void
    {
        $record = $this->record('2026-09-04', water: 1300);
        VitalSign::factory()->for($record)->create(['temperature' => 37.8, 'systolic_bp' => 128, 'spo2' => 97]);

        $indicator = $this->only(RiskCategory::Infection);

        $this->assertStringContainsString('37.5', $indicator->title);
    }

    public function test_血圧とspo2の異常をそれぞれ検出する(): void
    {
        $record = $this->record('2026-09-04', water: 1300);
        VitalSign::factory()->for($record)->create(['temperature' => 36.4, 'systolic_bp' => 188, 'spo2' => 91]);

        $indicators = $this->calculate();

        $this->assertCount(2, $indicators, '血圧とSpO2は別の指摘として挙げる');

        foreach ($indicators as $indicator) {
            $this->assertSame(RiskSeverity::High, $indicator->severity);
            $this->assertNotSame([], $indicator->evidence);
        }
    }

    public function test_未測定のバイタルは異常と判定しない(): void
    {
        $record = $this->record('2026-09-04', water: 1300);
        VitalSign::factory()->for($record)->create(['temperature' => null, 'systolic_bp' => null, 'spo2' => null]);

        $this->assertSame([], $this->calculate());
    }

    // ---------------------------------------------------------------
    // ヒヤリハット
    // ---------------------------------------------------------------

    public function test_直近3ヶ月で2件以上のヒヤリハットを転倒リスクとして検出する(): void
    {
        IncidentReport::factory()->for($this->resident)->fall()->create(['occurred_at' => now()->subMonth()]);
        IncidentReport::factory()->for($this->resident)->fall()->create(['occurred_at' => now()->subMonths(2)]);

        $indicator = $this->only(RiskCategory::Fall);

        $this->assertSame(RiskSeverity::High, $indicator->severity);
    }

    public function test_期間外のヒヤリハットは数えない(): void
    {
        IncidentReport::factory()->for($this->resident)->fall()->create(['occurred_at' => now()->subMonth()]);
        IncidentReport::factory()->for($this->resident)->fall()->create(['occurred_at' => now()->subMonths(8)]);

        $this->assertSame([], $this->calculate());
    }

    // ---------------------------------------------------------------

    public function test_記録がなければ何も検出しない(): void
    {
        $this->assertSame([], $this->calculate());
    }

    public function test_同じ入力なら必ず同じ結果になる(): void
    {
        // 再現性こそがルールベースを選んだ理由である
        $this->weight('2026-08-01', 42.6);
        $this->weight('2026-09-01', 41.1);

        $first = $this->calculate();
        $second = $this->calculate();

        $this->assertEquals($first, $second);
    }

    // ---------------------------------------------------------------

    /** @return list<RiskIndicator> */
    private function calculate(): array
    {
        return $this->calculator->calculate(
            $this->resident->fresh(),
            Carbon::parse('2026-06-01'),
            Carbon::parse('2026-09-30'),
        );
    }

    private function only(RiskCategory $category): RiskIndicator
    {
        $indicators = array_values(array_filter(
            $this->calculate(),
            static fn ($indicator): bool => $indicator->category === $category,
        ));

        $this->assertCount(1, $indicators, "{$category->label()} の指摘が1件あるはず");

        return $indicators[0];
    }

    private function weight(string $date, float $kg): void
    {
        WeightRecord::factory()->for($this->resident)->create([
            'measured_on' => $date,
            'weight_kg' => $kg,
        ]);
    }

    private function record(string $date, ?int $water): ServiceRecord
    {
        return ServiceRecord::factory()->for($this->resident)->create([
            'service_date' => $date,
            'total_water_ml' => $water,
        ]);
    }
}
