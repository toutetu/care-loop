<?php

namespace Tests\Feature;

use App\Enums\BathingType;
use App\Enums\UserRole;
use App\Models\Facility;
use App\Models\Resident;
use App\Models\ServiceRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * 一括入力（入浴・食事・バイタル）。
 *
 * 確かめたいのは、入力できることより「入れた分が消えないこと」と
 * 「誰が入れたかが残ること」である。記録入力画面と同じ表へ書くので、
 * どちらから入れても同じ記録になる。
 */
class BatchEntryTest extends TestCase
{
    use RefreshDatabase;

    private Facility $facility;

    private User $staff;

    private ServiceRecord $record;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facility = Facility::factory()->create();
        $this->staff = User::factory()->create([
            'facility_id' => $this->facility->id,
            'role' => UserRole::Staff,
        ]);

        $resident = Resident::factory()->for($this->facility)->create();

        $this->record = ServiceRecord::factory()->for($resident)->create([
            'service_date' => today(),
            'attendance_status' => 'attended',
        ]);
    }

    // ---------------------------------------------------------------
    // 画面
    // ---------------------------------------------------------------

    public function test_その日に利用のある方だけが並ぶ(): void
    {
        // 欠席の方まで並べると、入力欄の数だけが増えて探しにくい
        $absent = Resident::factory()->for($this->facility)->create();
        ServiceRecord::factory()->for($absent)->create([
            'service_date' => today(),
            'attendance_status' => 'absent',
        ]);

        $this->actingAs($this->staff)->get('/records/batch/bathing')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('records/batch')
                ->where('kind', 'bathing')
                ->has('rows', 1)
            );
    }

    public function test_別の事業所の記録は並ばない(): void
    {
        $outsider = Resident::factory()->for(Facility::factory()->create())->create();
        ServiceRecord::factory()->for($outsider)->create([
            'service_date' => today(),
            'attendance_status' => 'attended',
        ]);

        $this->actingAs($this->staff)->get('/records/batch/vital')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('rows', 1));
    }

    public function test_知らない種別は404(): void
    {
        $this->actingAs($this->staff)->get('/records/batch/weight')->assertNotFound();
    }

    // ---------------------------------------------------------------
    // 保存
    // ---------------------------------------------------------------

    public function test_入浴を入れると記録者つきで残る(): void
    {
        $this->actingAs($this->staff)->post('/records/batch/bathing', [
            'entries' => [
                $this->record->id => ['bathing_type' => 'bath'],
            ],
        ])->assertRedirect();

        $bathing = $this->record->bathingRecords()->sole();

        $this->assertSame(BathingType::Bath, $bathing->bathing_type);
        $this->assertSame($this->staff->id, $bathing->recorded_by);
        $this->assertNotNull($bathing->bathed_at);
    }

    public function test_前に入れた分を消さずに積む(): void
    {
        // 午前に入浴、午後に清拭という日がある。上書きすると片方が消える。
        $this->record->bathingRecords()->create([
            'recorded_by' => $this->staff->id,
            'bathing_type' => BathingType::Bath,
        ]);

        $this->actingAs($this->staff)->post('/records/batch/bathing', [
            'entries' => [
                $this->record->id => ['bathing_type' => 'wipe'],
            ],
        ]);

        $this->assertSame(2, $this->record->bathingRecords()->count());
    }

    public function test_空欄の行には記録を作らない(): void
    {
        // 20名ぶんの欄を出して5名だけ入れることのほうが多い。
        // 空欄まで記録にすると、測っていないものが記録として残る。
        $this->actingAs($this->staff)->post('/records/batch/vital', [
            'entries' => [
                $this->record->id => [
                    'temperature' => '',
                    'systolic_bp' => '',
                    'spo2' => '',
                ],
            ],
        ])->assertSessionHas('success', '入力された項目がありませんでした。');

        $this->assertSame(0, $this->record->vitalSigns()->count());
    }

    public function test_測っていない項目は0ではなく空で残る(): void
    {
        $this->actingAs($this->staff)->post('/records/batch/vital', [
            'entries' => [
                $this->record->id => ['temperature' => '37.2', 'systolic_bp' => ''],
            ],
        ]);

        $vital = $this->record->vitalSigns()->sole();

        $this->assertSame('37.2', (string) $vital->temperature);
        $this->assertNull($vital->systolic_bp, '未測定は0で埋めない');
        $this->assertSame($this->staff->id, $vital->recorded_by);
    }

    public function test_むせ込みだけでも食事の記録として残る(): void
    {
        // 摂取量を測っていなくても、むせ込みは伝えるべき事実である
        $this->actingAs($this->staff)->post('/records/batch/meal', [
            'entries' => [
                $this->record->id => ['meal_type' => 'lunch', 'choking' => '1'],
            ],
        ]);

        $meal = $this->record->mealRecords()->sole();

        $this->assertTrue($meal->choking);
        $this->assertNull($meal->staple_rate);
        $this->assertSame($this->staff->id, $meal->recorded_by);
    }

    public function test_範囲外の摂取割合は未入力として扱う(): void
    {
        // 桁を打ち間違えた 999% を記録として残さない
        $this->actingAs($this->staff)->post('/records/batch/meal', [
            'entries' => [
                $this->record->id => ['staple_rate' => '999', 'side_rate' => '80'],
            ],
        ]);

        $meal = $this->record->mealRecords()->sole();

        $this->assertNull($meal->staple_rate);
        $this->assertSame(80, $meal->side_rate);
    }

    public function test_別の事業所の記録には書けない(): void
    {
        $outsider = Resident::factory()->for(Facility::factory()->create())->create();
        $other = ServiceRecord::factory()->for($outsider)->create([
            'service_date' => today(),
            'attendance_status' => 'attended',
        ]);

        $this->actingAs($this->staff)->post('/records/batch/bathing', [
            'entries' => [
                $other->id => ['bathing_type' => 'bath'],
            ],
        ])->assertForbidden();

        $this->assertSame(0, $other->bathingRecords()->count());
    }

    public function test_弾かれたら同じ送信の他の行も書かれない(): void
    {
        // 何人ぶんが入ったのか分からない状態が、いちばん困る
        $outsider = Resident::factory()->for(Facility::factory()->create())->create();
        $other = ServiceRecord::factory()->for($outsider)->create([
            'service_date' => today(),
            'attendance_status' => 'attended',
        ]);

        $this->actingAs($this->staff)->post('/records/batch/bathing', [
            'entries' => [
                $this->record->id => ['bathing_type' => 'bath'],
                $other->id => ['bathing_type' => 'bath'],
            ],
        ])->assertForbidden();

        $this->assertSame(0, $this->record->bathingRecords()->count());
    }

    public function test_未ログインでは開けない(): void
    {
        $this->get('/records/batch/bathing')->assertRedirect();
    }
}
