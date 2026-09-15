<?php

namespace Tests\Feature;

use App\Enums\LlmJobStatus;
use App\Enums\UserRole;
use App\Models\Facility;
use App\Models\LlmJob;
use App\Models\Resident;
use App\Models\ServiceRecord;
use App\Models\User;
use App\Support\QueueHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * AI処理の実行状況。
 *
 * 職員が必要とするのは、押した処理が通ったのか失敗したのか、
 * 失敗したなら次に何をすればよいのかである。
 * 費用とトークン数はAI利用ログ（管理者のみ）が扱う。
 */
class LlmJobIndexTest extends TestCase
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
            'role' => UserRole::Staff,
        ]);
    }

    public function test_一般職員でも開ける(): void
    {
        // 自分が動かしたAIがどうなったかは、日々の業務に必要な情報である
        $this->actingAs($this->staff)->get('/llm-jobs')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('llm/jobs')
                ->where('canViewLlmLogs', false)
            );
    }

    public function test_失敗には次にどうするかを添える(): void
    {
        // 職員に技術的な文言を見せても対処できない。
        // 再試行で直るのか、担当者の対応が要るのかを区別して渡す。
        LlmJob::factory()->create([
            'requested_by' => $this->staff->id,
            'status' => LlmJobStatus::Failed,
            'error_type' => 'rate_limit_error',
        ]);

        $this->actingAs($this->staff)->get('/llm-jobs')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('jobs.data.0.errorLabel', 'レート制限')
                ->where('jobs.data.0.errorMessage', '混み合っています。自動で再試行します。')
                ->where('jobs.data.0.isRetryable', true)
                ->where('jobs.data.0.needsOperatorAttention', false)
            );
    }

    public function test_設定の誤りは要対応として渡す(): void
    {
        LlmJob::factory()->create([
            'requested_by' => $this->staff->id,
            'status' => LlmJobStatus::Failed,
            'error_type' => 'authentication_error',
        ]);

        $this->actingAs($this->staff)->get('/llm-jobs')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('jobs.data.0.isRetryable', false)
                ->where('jobs.data.0.needsOperatorAttention', true)
            );
    }

    public function test_失敗のみに絞り込める(): void
    {
        LlmJob::factory()->create([
            'requested_by' => $this->staff->id,
            'status' => LlmJobStatus::Succeeded,
        ]);
        LlmJob::factory()->create([
            'requested_by' => $this->staff->id,
            'status' => LlmJobStatus::Failed,
            'error_type' => 'schema_mismatch',
        ]);

        $this->actingAs($this->staff)->get('/llm-jobs?status=failed')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('onlyFailed', true)
                ->has('jobs.data', 1)
                // 件数は絞り込んでも全体のまま
                ->where('counts.total', 2)
                ->where('counts.failed', 1)
            );
    }

    public function test_対象の記録へ辿れる(): void
    {
        // どの記録の話なのか分からないと、確認のしようがない
        $resident = Resident::factory()->for($this->facility)->create(['name' => '佐藤 ハナ']);
        $record = ServiceRecord::factory()->for($resident)->create(['service_date' => today()]);

        LlmJob::factory()->create([
            'requested_by' => $this->staff->id,
            'target_type' => $record->getMorphClass(),
            'target_id' => $record->id,
            'status' => LlmJobStatus::Succeeded,
        ]);

        $this->actingAs($this->staff)->get('/llm-jobs')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('jobs.data.0.targetRecordId', $record->id)
                ->where('jobs.data.0.targetLabel', fn (string $label) => str_contains($label, '佐藤 ハナ'))
            );
    }

    public function test_他の事業所の実行は見えない(): void
    {
        // 誰が何を実行したかは、所属をまたいで見えてはいけない
        $outsider = User::factory()->create([
            'facility_id' => Facility::factory()->create()->id,
        ]);

        LlmJob::factory()->create([
            'requested_by' => $outsider->id,
            'status' => LlmJobStatus::Succeeded,
        ]);

        $this->actingAs($this->staff)->get('/llm-jobs')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('jobs.data', 0)
                ->where('counts.total', 0)
            );
    }

    public function test_未ログインでは開けない(): void
    {
        $this->get('/llm-jobs')->assertRedirect();
    }

    // ---------------------------------------------------------------
    // ワーカーが止まっているとき
    // ---------------------------------------------------------------

    public function test_処理が始まらないジョブには警告を添える(): void
    {
        // 「待てば終わる」と「待っても終わらない」を同じ見た目にしない
        LlmJob::factory()->create([
            'requested_by' => $this->staff->id,
            'status' => LlmJobStatus::Queued,
            'created_at' => now()->subSeconds(LlmJob::DELAYED_AFTER_SECONDS + 1),
        ]);

        $this->actingAs($this->staff)->get('/llm-jobs')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('jobs.data.0.statusLabel', '待機中')
                ->where('jobs.data.0.isDelayed', true)
            );
    }

    public function test_見捨てたジョブは失敗として出す(): void
    {
        LlmJob::factory()->create([
            'requested_by' => $this->staff->id,
            'status' => LlmJobStatus::Queued,
            'created_at' => now()->subSeconds(LlmJob::ABANDON_QUEUED_AFTER_SECONDS + 1),
        ]);

        $this->actingAs($this->staff)->get('/llm-jobs')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('jobs.data.0.status', 'failed')
                ->where('jobs.data.0.isDelayed', false)
                ->where('jobs.data.0.errorLabel', '未処理のまま時間切れ')
                ->where('jobs.data.0.needsOperatorAttention', true)
                // 一覧では失敗なのに件数では実行中、という食い違いを作らない。
                // 実行中に数えると画面が読み直しを止められない。
                ->where('counts.failed', 1)
                ->where('counts.running', 0)
            );

        // 失敗だけの絞り込みにも入る
        $this->actingAs($this->staff)->get('/llm-jobs?status=failed')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('jobs.data', 1));
    }

    public function test_待機が長引いただけのジョブはまだ実行中に数える(): void
    {
        LlmJob::factory()->create([
            'requested_by' => $this->staff->id,
            'status' => LlmJobStatus::Queued,
            'created_at' => now()->subSeconds(LlmJob::DELAYED_AFTER_SECONDS + 1),
        ]);

        $this->actingAs($this->staff)->get('/llm-jobs')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('counts.running', 1)
                ->where('counts.failed', 0)
            );
    }

    public function test_ワーカーの最終稼働を出す(): void
    {
        // ワーカーが止まっていることに、画面から気づけるようにする
        $this->actingAs($this->staff)->get('/llm-jobs')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('worker.lastSeenAt', null)
                ->where('worker.connection', 'sync')
            );

        QueueHeartbeat::touch();

        $this->actingAs($this->staff)->get('/llm-jobs')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('worker.lastSeenAt', fn (?string $value) => $value !== null)
            );
    }
}
