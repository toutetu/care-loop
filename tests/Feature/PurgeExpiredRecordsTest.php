<?php

namespace Tests\Feature;

use App\Enums\LlmFeature;
use App\Enums\NoteInputMethod;
use App\Enums\ResidentAccessAction;
use App\Models\AuditLog;
use App\Models\CarePlan;
use App\Models\Facility;
use App\Models\LlmJob;
use App\Models\RecordNote;
use App\Models\Resident;
use App\Models\ResidentAccessLog;
use App\Models\RiskAssessment;
use App\Models\ServiceRecord;
use App\Models\WeightRecord;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * 保存期間を過ぎた記録の物理削除（要件定義 8章、9.3.2節 施策6）。
 *
 * 消したものは戻せない。確かめたいのは、消すべきものが残らないことより先に、
 * 消してはいけないものを消さないことである。
 */
class PurgeExpiredRecordsTest extends TestCase
{
    use RefreshDatabase;

    private Facility $facility;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facility = Facility::factory()->create();
    }

    // ---------------------------------------------------------------
    // 消す
    // ---------------------------------------------------------------

    public function test_利用終了から保存期間を過ぎたご利用者は記録ごと消える(): void
    {
        $resident = $this->resident(endedAt: today()->subYears(5)->subDay());
        $record = $this->recordOf($resident);
        $this->attachEverything($resident, $record);

        $this->artisan('careloop:purge-expired-records')->assertSuccessful();

        $this->assertSame(0, Resident::withTrashed()->count());
        $this->assertSame(0, ServiceRecord::withTrashed()->count());
        $this->assertSame(0, RecordNote::query()->count());
        $this->assertSame(0, WeightRecord::query()->count());
        $this->assertSame(0, CarePlan::query()->count());
        $this->assertSame(0, RiskAssessment::query()->count());
        $this->assertSame(0, ResidentAccessLog::query()->count());
        // 外部キーでつながっていない表も残さない。編集履歴には旧住所などが入っている
        $this->assertSame(0, AuditLog::query()->count());
        $this->assertSame(0, LlmJob::query()->count());
    }

    public function test_論理削除されたご利用者も保存期間を過ぎれば消す(): void
    {
        // 論理削除のまま残置しない（要件定義 8章）
        $resident = $this->resident(endedAt: today()->subYears(6));
        $resident->delete();

        $this->artisan('careloop:purge-expired-records')->assertSuccessful();

        $this->assertSame(0, Resident::withTrashed()->count());
    }

    // ---------------------------------------------------------------
    // 消さない
    // ---------------------------------------------------------------

    public function test_保存期間の途中のご利用者は消さない(): void
    {
        $resident = $this->resident(endedAt: today()->subYears(5));
        $this->recordOf($resident);

        $this->artisan('careloop:purge-expired-records')->assertSuccessful();

        $this->assertSame(1, Resident::withTrashed()->count());
        $this->assertSame(1, ServiceRecord::withTrashed()->count());
    }

    public function test_利用中のご利用者は古い記録も消さない(): void
    {
        // 完結の日を利用終了日とみる。記録1件ごとの日付で消すと、
        // そう解釈する自治体では保存期間の途中で消すことになる。
        $resident = $this->resident(endedAt: null);
        $this->recordOf($resident, today()->subYears(6));

        $this->artisan('careloop:purge-expired-records')->assertSuccessful();

        $this->assertSame(1, ServiceRecord::withTrashed()->count());
    }

    public function test_利用終了日のないご利用者は論理削除されていても消さない(): void
    {
        // いつ完結したのかが分からないものは、消してよいかも分からない
        $resident = $this->resident(endedAt: null);
        $resident->delete();
        $this->travel(6)->years();

        $this->artisan('careloop:purge-expired-records')->assertSuccessful();

        $this->assertSame(1, Resident::withTrashed()->count());
    }

    public function test_ほかのご利用者の記録と履歴は残る(): void
    {
        $expired = $this->resident(endedAt: today()->subYears(6));
        $this->attachEverything($expired, $this->recordOf($expired));

        $active = $this->resident(endedAt: null);
        $activeRecord = $this->recordOf($active);
        $this->attachEverything($active, $activeRecord);

        $this->artisan('careloop:purge-expired-records')->assertSuccessful();

        $this->assertSame([$active->id], Resident::withTrashed()->pluck('id')->all());
        $this->assertSame([$activeRecord->id], ServiceRecord::withTrashed()->pluck('id')->all());
        $this->assertSame(1, ResidentAccessLog::query()->count());
        $this->assertSame(2, LlmJob::query()->count());
        $this->assertTrue(AuditLog::query()->where('auditable_id', $active->id)->exists());
        $this->assertFalse(AuditLog::query()->where('auditable_id', $expired->id)->exists());
    }

    public function test_件数だけを確かめるときは何も消さない(): void
    {
        $resident = $this->resident(endedAt: today()->subYears(6));
        $this->recordOf($resident);

        $cutoff = today()->subYears(5)->toDateString();

        $this->artisan('careloop:purge-expired-records', ['--dry-run' => true])
            ->expectsOutputToContain("利用終了日が {$cutoff} より前のご利用者は 1 名、記録は 1 件です。--dry-run のため削除していません。")
            ->assertSuccessful();

        $this->assertSame(1, Resident::withTrashed()->count());
        $this->assertSame(1, ServiceRecord::withTrashed()->count());
    }

    public function test_消す前に件数をログに残す(): void
    {
        // 誤って消したときに、何がどれだけ消えたのかを後から辿れるようにする
        $resident = $this->resident(endedAt: today()->subYears(6));
        $this->recordOf($resident);

        Log::spy();

        $this->artisan('careloop:purge-expired-records')->assertSuccessful();

        Log::shouldHaveReceived('info')->withArgs(
            fn (string $message, array $context): bool => str_contains($message, '保存期間')
                && $context['residents'] === 1
                && $context['service_records'] === 1
                && $context['resident_ids'] === [$resident->id],
        )->once();
    }

    public function test_毎日夜中に動く(): void
    {
        // 本物の事業所でこそ要る処理なので、デモかどうかに関わらず登録する
        config(['careloop.is_demo' => false]);

        $event = collect(app(Schedule::class)->events())->first(
            fn (Event $event): bool => str_contains((string) $event->command, 'careloop:purge-expired-records'),
        );

        $this->assertNotNull($event);
        $this->assertSame('0 3 * * *', $event->expression);
    }

    // ---------------------------------------------------------------

    private function resident(?\DateTimeInterface $endedAt): Resident
    {
        return Resident::factory()->for($this->facility)->create([
            'ended_at' => $endedAt,
        ]);
    }

    private function recordOf(Resident $resident, ?\DateTimeInterface $date = null): ServiceRecord
    {
        return $resident->serviceRecords()->create([
            'service_date' => $date ?? today()->subYears(6),
            'attendance_status' => 'attended',
        ]);
    }

    /** ご利用者にぶら下がるものを、外部キーでつながるものも、つながらないものも足す。 */
    private function attachEverything(Resident $resident, ServiceRecord $record): void
    {
        $record->notes()->create([
            'body' => '午前中は体操に参加',
            'input_method' => NoteInputMethod::Voice,
        ]);
        WeightRecord::factory()->for($resident)->create();
        CarePlan::factory()->for($resident)->create();
        RiskAssessment::factory()->for($resident)->create();
        ResidentAccessLog::create([
            'resident_id' => $resident->id,
            'action' => ResidentAccessAction::ViewResident,
        ]);
        LlmJob::factory()->create([
            'feature' => LlmFeature::RiskDetection,
            'target_type' => $resident->getMorphClass(),
            'target_id' => $resident->id,
        ]);
        LlmJob::factory()->create([
            'feature' => LlmFeature::VoiceTransform,
            'target_type' => $record->getMorphClass(),
            'target_id' => $record->id,
        ]);
    }
}
