<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Facility;
use App\Models\LlmJob;
use App\Models\Resident;
use App\Models\ServiceRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * 記録の一覧。
 *
 * ダッシュボードは朝礼で全体を見る場所で、ここは記録を埋めていく場所である。
 * この画面を開く動機は「まだ終わっていないものを片付ける」ことなので、
 * 未確定のものが先に目に入る必要がある。
 */
class RecordIndexTest extends TestCase
{
    use RefreshDatabase;

    private Facility $facility;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facility = Facility::factory()->create();
        $this->staff = User::factory()->create([
            'facility_id' => $this->facility->id,
            'role' => UserRole::Manager,
        ]);
    }

    // ---------------------------------------------------------------
    // 並び順と絞り込み
    // ---------------------------------------------------------------

    public function test_未確定の記録が先に並ぶ(): void
    {
        // 確定済みが上に並んでいると、毎回スクロールして探すことになる
        $this->record('アオキ ハル', confirmed: true);
        $this->record('ヤマダ タロウ', confirmed: false);

        $this->actingAs($this->staff)->get('/records')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('records/index')
                ->has('records', 2)
                ->where('records.0.status', 'draft')
                ->where('records.1.status', 'confirmed')
            );
    }

    public function test_未確定だけに絞り込める(): void
    {
        $this->record('アオキ ハル', confirmed: true);
        $this->record('ヤマダ タロウ', confirmed: false);

        $this->actingAs($this->staff)->get('/records?status=unconfirmed')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('onlyUnconfirmed', true)
                ->has('records', 1)
                // 件数は絞り込んでも全体のまま。何件中いくつなのかが分からなくなる。
                ->where('counts.total', 2)
                ->where('counts.unconfirmed', 1)
            );
    }

    public function test_ai下書きの件数を別に数える(): void
    {
        // 職員がまだ読んでいない生成文が何件あるかは、未確定とは別の情報
        $record = $this->record('ヤマダ タロウ', confirmed: false);
        $record->forceFill(['llm_job_id' => LlmJob::factory()->create()->id])->save();

        $this->actingAs($this->staff)->get('/records')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('counts.aiDraft', 1)
                ->where('records.0.status', 'ai_draft')
            );
    }

    // ---------------------------------------------------------------
    // 日付
    // ---------------------------------------------------------------

    public function test_日付を指定して表示できる(): void
    {
        $this->record('ヤマダ タロウ', confirmed: true, date: today()->subDays(5));

        $this->actingAs($this->staff)
            ->get('/records?date='.today()->subDays(5)->toDateString())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('day.date', today()->subDays(5)->toDateString())
                ->has('records', 1)
            );
    }

    public function test_日付として読めない指定は既定の動きに戻す(): void
    {
        // URLを手で書き換えられても画面が落ちないようにする
        $this->record('ヤマダ タロウ', confirmed: true);

        $this->actingAs($this->staff)->get('/records?date=きのう')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('day.isToday', true));
    }

    public function test_本日に記録がなければ直近の利用日へ下がる(): void
    {
        $this->record('ヤマダ タロウ', confirmed: true, date: today()->subDays(3));

        $this->actingAs($this->staff)->get('/records')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('day.isToday', false)
                ->where('day.date', today()->subDays(3)->toDateString())
            );
    }

    // ---------------------------------------------------------------
    // 権限
    // ---------------------------------------------------------------

    public function test_他の事業所の記録は出さない(): void
    {
        $other = Resident::factory()->for(Facility::factory()->create())->create();
        ServiceRecord::factory()->for($other)->create(['service_date' => today()]);

        $this->record('ヤマダ タロウ', confirmed: true);

        $this->actingAs($this->staff)->get('/records')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('records', 1));
    }

    public function test_一般職員にも他人の記録を編集可として渡す(): void
    {
        // 担当が入れ替わる現場で、最初に触れた職員しか書けないと
        // 入浴を担当した職員が入浴の記録を残せない。
        $record = $this->record('ヤマダ タロウ', confirmed: false);

        $otherStaff = User::factory()->create([
            'facility_id' => $this->facility->id,
            'role' => UserRole::Staff,
        ]);

        $this->actingAs($otherStaff)->get('/records')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('records.0.recordId', $record->id)
                ->where('records.0.canEdit', true)
            );
    }

    public function test_未ログインでは開けない(): void
    {
        $this->get('/records')->assertRedirect();
    }

    // ---------------------------------------------------------------

    private function record(string $kana, bool $confirmed, ?object $date = null): ServiceRecord
    {
        $resident = Resident::factory()->for($this->facility)->create(['name_kana' => $kana]);

        return ServiceRecord::factory()->for($resident)->create([
            'service_date' => $date ?? today(),
            'recorded_by' => $this->staff->id,
            'confirmed_at' => $confirmed ? now() : null,
        ]);
    }
}
