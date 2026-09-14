<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Llm\Contracts\LlmClient;
use App\Llm\Exceptions\LlmException;
use App\Models\Facility;
use App\Models\LlmJob;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia;
use Mockery;
use Tests\TestCase;

/**
 * 公開デモとして出すための守り。
 *
 * デモは誰でも開ける場所に置き、ログイン情報も画面に書いてある。
 * その状態で実APIを呼べるようにするなら、費用が暴走しない仕組みが要る。
 */
class PublicDemoGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private Resident $resident;

    protected function setUp(): void
    {
        parent::setUp();

        $facility = Facility::factory()->create();
        $this->staff = User::factory()->create([
            'facility_id' => $facility->id,
            'role' => UserRole::Staff,
        ]);
        $this->resident = Resident::factory()->for($facility)->create();

        RateLimiter::clear('llm');

        // ゲートウェイはレート制限を受けると待ってから再試行する。
        // その待ち時間をテストで実際に消費する理由はない。
        Sleep::fake();
    }

    // ---------------------------------------------------------------
    // 流量制限
    // ---------------------------------------------------------------

    public function test_ai実行を連続で呼ぶと制限される(): void
    {
        // 月次の上限に達してから止まるのでは、その月のデモ全体が動かなくなる。
        // デモ用アカウントは複数人で共有されるため、一人の連打で
        // 他の人が何も試せなくなる。
        $this->mock(LlmClient::class, function (Mockery\MockInterface $mock): void {
            $mock->shouldReceive('send')->andThrow(LlmException::rateLimited('429'));
        });

        $url = route('llm.risk-detection', $this->resident);

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($this->staff)->post($url)->assertRedirect();
        }

        $this->actingAs($this->staff)
            ->from(route('residents.show', $this->resident))
            ->post($url)
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'おいてからお試しください'));
    }

    public function test_制限に達してもエラー画面にはしない(): void
    {
        // 429のエラーページが出ると、入力していた記録も見えなくなる。
        // 元の画面へ戻し、日本語で理由を伝える。
        $this->mock(LlmClient::class, function (Mockery\MockInterface $mock): void {
            $mock->shouldReceive('send')->andThrow(LlmException::rateLimited('429'));
        });

        $url = route('llm.risk-detection', $this->resident);
        $from = route('residents.show', $this->resident);

        // 3回までは通る。4回目が制限に当たる。
        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($this->staff)->from($from)->post($url);
        }

        $this->actingAs($this->staff)->from($from)->post($url)
            ->assertStatus(302)
            ->assertRedirect($from);
    }

    public function test_制限で弾かれた実行はジョブを作らない(): void
    {
        // 呼び出していない実行がログに並ぶと、費用の追跡が狂う
        $this->mock(LlmClient::class, function (Mockery\MockInterface $mock): void {
            $mock->shouldReceive('send')->andThrow(LlmException::rateLimited('429'));
        });

        $url = route('llm.risk-detection', $this->resident);

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($this->staff)->post($url);
        }

        // 通ったのは3回まで
        $this->assertSame(3, LlmJob::query()->count());
    }

    public function test_ログインの試行回数制限には手を触れない(): void
    {
        // AI実行の制限を日本語のリダイレクトに変えたとき、認証の制限まで
        // 巻き込むと、総当たりを試みている側に「まだ弾かれていない」と
        // 読める応答を返すことになる。
        for ($i = 0; $i < 6; $i++) {
            $response = $this->post('/login', [
                'email' => $this->staff->email,
                'password' => 'wrong-password',
            ]);
        }

        $response->assertStatus(429);
    }

    // ---------------------------------------------------------------
    // 新規登録
    // ---------------------------------------------------------------

    public function test_新規登録を閉じると画面もフォームも404になる(): void
    {
        // ログイン情報を公開したまま登録も開けておくと、
        // 実APIを無制限に呼び出せる状態になる。
        config(['careloop.features.registration' => false]);

        $this->get('/register')->assertNotFound();

        $this->post('/register', [
            'name' => '誰か',
            'email' => 'someone@example.com',
            'password' => 'Password!234567',
            'password_confirmation' => 'Password!234567',
        ])->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'someone@example.com']);
    }

    public function test_パスワード再設定を閉じると404になる(): void
    {
        // メール送信の手配がないため、押しても何も届かない
        config(['careloop.features.password_reset' => false]);

        $this->get('/forgot-password')->assertNotFound();
        $this->post('/forgot-password', ['email' => $this->staff->email])->assertNotFound();
    }

    public function test_閉じてもログイン自体は使える(): void
    {
        // 閉じるのは公開に伴う2つだけで、認証そのものは止めない
        config([
            'careloop.features.registration' => false,
            'careloop.features.password_reset' => false,
        ]);

        $this->get('/login')->assertOk();
    }

    public function test_既定では新規登録を閉じない(): void
    {
        // ローカルでの開発と、この先の実運用を妨げない
        config(['careloop.features.registration' => true]);

        $this->get('/register')->assertOk();
    }

    public function test_閉じている機能のルートは常に登録されている(): void
    {
        // 【今回の失敗を繰り返さないための確認】
        // Fortify の features から外すとルートごと消え、Wayfinder が
        // resources/js/routes/register.ts を生成しなくなる。画面側は
        // それを import しているため、ビルドが壊れる。
        // 遮断はミドルウェアで行い、ルートは環境に関わらず存在させる。
        config([
            'careloop.features.registration' => false,
            'careloop.features.password_reset' => false,
        ]);

        foreach (['register', 'register.store', 'password.request', 'password.update'] as $name) {
            $this->assertTrue(
                Route::has($name),
                "ルート {$name} が登録されていません。フロントエンドのビルドが壊れます。",
            );
        }
    }

    public function test_パスワード再設定が閉じていればログイン画面にリンクを出さない(): void
    {
        config(['careloop.features.password_reset' => false]);

        $this->get('/login')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canResetPassword', false));
    }

    // ---------------------------------------------------------------
    // ログイン画面のデモ用アカウント
    // ---------------------------------------------------------------

    public function test_デモ環境ではログイン画面にアカウントを出す(): void
    {
        // 見に来た人はこのアプリのことも介護の業務も知らない。
        // ログイン情報を探しに別の画面へ戻らせると、そこで離脱する。
        config(['careloop.is_demo' => true]);

        $this->get('/login')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('auth/login')
                ->has('demoAccounts', 3)
                ->where('demoAccounts.0.email', 'admin@example.com')
            );
    }

    public function test_デモ環境でなければアカウントを渡さない(): void
    {
        // 本物の事業所で動かすときに、同じ画面が出てはならない。
        // 画面側で出し分けるのではなく、そもそもサーバーから渡さない。
        config(['careloop.is_demo' => false]);

        $this->get('/login')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('demoAccounts', null)
            );
    }

    // ---------------------------------------------------------------
    // デモデータの作り直し
    // ---------------------------------------------------------------

    public function test_デモ環境でなければ作り直しコマンドは動かない(): void
    {
        // ご利用者も記録も職員も消すコマンドである。
        // 本物の事業所のデータベースへ向けて実行されたら取り返しがつかない。
        config(['careloop.is_demo' => false]);

        $this->artisan('careloop:reset-demo --force')->assertFailed();

        $this->assertDatabaseHas('users', ['id' => $this->staff->id]);
    }
}
