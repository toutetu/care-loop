<?php

namespace Tests\Feature;

use App\Models\CarePlan;
use App\Models\CarePlanGoal;
use App\Models\IncidentReport;
use App\Models\LlmJob;
use App\Models\MealRecord;
use App\Models\Resident;
use App\Models\ServiceRecord;
use App\Models\VitalSign;
use App\Models\WeightRecord;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 介護記録ドメインのモデルとルールベース判定を検証する。
 *
 * ここで検証しているのは、LLMを使わずに算出できる指標である。
 * 数値で判定できるものは決定的なロジックで計算し、同じ入力なら必ず同じ結果に
 * なることを保証する（要件定義 7.1節）。LLMに算術を任せるとこの保証が失われる。
 */
class CareDomainTest extends TestCase
{
    use RefreshDatabase;

    public function test_利用者から計画書と短期目標まで組み立てられる(): void
    {
        $resident = Resident::factory()->create();
        $plan = CarePlan::factory()->for($resident)->create();
        CarePlanGoal::factory()->count(3)->for($plan)->create();

        $this->assertSame(1, $resident->carePlans()->count());
        $this->assertSame(3, $plan->goals()->count());
        $this->assertNotNull($resident->facility);
        $this->assertNotNull($resident->careLevel);
    }

    public function test_短期目標は並び順で取得される(): void
    {
        $plan = CarePlan::factory()->create();
        CarePlanGoal::factory()->for($plan)->create(['sort_order' => 2, 'goal_text' => '三番目']);
        CarePlanGoal::factory()->for($plan)->create(['sort_order' => 0, 'goal_text' => '一番目']);
        CarePlanGoal::factory()->for($plan)->create(['sort_order' => 1, 'goal_text' => '二番目']);

        $this->assertSame(
            ['一番目', '二番目', '三番目'],
            $plan->goals()->pluck('goal_text')->all()
        );
    }

    public function test_体重の減少率が前月比で算出される(): void
    {
        $resident = Resident::factory()->create();

        WeightRecord::factory()->for($resident)->create([
            'measured_on' => '2026-08-01',
            'weight_kg' => 42.6,
        ]);

        $current = WeightRecord::factory()->for($resident)->create([
            'measured_on' => '2026-09-01',
            'weight_kg' => 41.1,
        ]);

        // (42.6 - 41.1) / 42.6 = 0.0352...
        $this->assertEqualsWithDelta(0.0352, $current->lossRateFromPrevious(), 0.0001);
    }

    public function test_減少率がしきい値を超えたことを判定できる(): void
    {
        $resident = Resident::factory()->create();
        WeightRecord::factory()->for($resident)->create(['measured_on' => '2026-08-01', 'weight_kg' => 42.6]);
        $current = WeightRecord::factory()->for($resident)->create(['measured_on' => '2026-09-01', 'weight_kg' => 41.1]);

        $threshold = (float) config('careloop.risk_thresholds.weight_loss_rate');

        $this->assertGreaterThanOrEqual($threshold, $current->lossRateFromPrevious());
    }

    public function test_比較対象がなければ減少率はnullになる(): void
    {
        // 判定できないことと「変化なし（0）」を区別する
        $record = WeightRecord::factory()->create(['measured_on' => '2026-09-01', 'weight_kg' => 41.1]);

        $this->assertNull($record->lossRateFromPrevious());
    }

    public function test_しきい値を外れたバイタルが検出される(): void
    {
        $vital = VitalSign::factory()->abnormal()->create();

        $this->assertTrue($vital->hasAbnormality());
        $this->assertEqualsCanonicalizing(
            ['temperature', 'systolic_bp', 'spo2'],
            $vital->abnormalItems()
        );
    }

    public function test_正常値なら異常は検出されない(): void
    {
        $vital = VitalSign::factory()->create([
            'temperature' => 36.4,
            'systolic_bp' => 128,
            'spo2' => 97,
        ]);

        $this->assertFalse($vital->hasAbnormality());
        $this->assertSame([], $vital->abnormalItems());
    }

    public function test_未測定の項目は異常と判定されない(): void
    {
        // 測っていないことと、ゼロだったことは違う
        $vital = VitalSign::factory()->create([
            'temperature' => null,
            'systolic_bp' => null,
            'spo2' => null,
        ]);

        $this->assertFalse($vital->hasAbnormality());
    }

    public function test_摂取量の少ない食事が検出される(): void
    {
        $low = MealRecord::factory()->lowIntake()->create();
        $normal = MealRecord::factory()->create(['staple_rate' => 100, 'side_rate' => 100]);

        $this->assertTrue($low->isLowIntake());
        $this->assertFalse($normal->isLowIntake());
    }

    public function test_同じ日に同じ食事区分を何度でも残せる(): void
    {
        // 以前は種別ごと1件に制限していた。しかし食後に摂取量を入れ直すと、
        // 前の職員が書いた1件を上書きすることになっていた。
        // 書き直すのではなく、その時点の事実として積む。
        $record = ServiceRecord::factory()->create();

        $first = MealRecord::factory()->for($record)->create([
            'meal_type' => 'lunch',
            'staple_rate' => 30,
        ]);
        $second = MealRecord::factory()->for($record)->create([
            'meal_type' => 'lunch',
            'staple_rate' => 80,
        ]);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, $record->mealRecords()->where('meal_type', 'lunch')->count());

        // 先に入れた分が消えていないこと
        $this->assertSame(30, $first->fresh()->staple_rate);
    }

    public function test_同じ利用者の同じ日に記録は1件しか作れない(): void
    {
        $resident = Resident::factory()->create();
        ServiceRecord::factory()->for($resident)->create(['service_date' => '2026-09-11']);

        $this->expectException(UniqueConstraintViolationException::class);

        ServiceRecord::factory()->for($resident)->create(['service_date' => '2026-09-11']);
    }

    public function test_直近3ヶ月のヒヤリハットを絞り込める(): void
    {
        $resident = Resident::factory()->create();

        IncidentReport::factory()->for($resident)->fall()->create(['occurred_at' => now()->subMonth()]);
        IncidentReport::factory()->for($resident)->fall()->create(['occurred_at' => now()->subMonths(2)]);
        IncidentReport::factory()->for($resident)->fall()->create(['occurred_at' => now()->subMonths(8)]);

        $recent = $resident->incidentReports()->recent()->ofCategory('fall')->count();

        $this->assertSame(2, $recent);
    }

    public function test_音声入力直後の記録は整形が未確認と判定される(): void
    {
        $record = ServiceRecord::factory()->rawOnly()->create([
            'llm_job_id' => LlmJob::factory()->create()->id,
        ]);

        $this->assertTrue($record->hasUnconfirmedAiDraft());
        $this->assertFalse($record->isConfirmed());
        $this->assertNotSame('', $record->load('notes')->combinedNoteText());
        $this->assertNull($record->record_text);
    }
}
