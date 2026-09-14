<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
    }

    /**
     * ログイン画面に並べるデモ用アカウント。
     *
     * 【デモ環境でしか返さない】
     * ログイン画面にIDとパスワードを書くのは、見に来た人がすぐ中を見られる
     * ようにするためである。本物の事業所で動かすときに同じ画面が出ては
     * ならないので、CARELOOP_DEMO が立っているときだけ返す。
     * 画面側で出し分けるのではなく、そもそもサーバーから渡さない。
     *
     * 【パスワードをここに書いてよい理由】
     * 対象はシーダーが作る架空の職員3名で、扱うデータもすべて架空である。
     * 実在の方の情報は入っていない。新規登録を閉じ、AI実行にも
     * 流量制限をかけたうえで公開している。
     *
     * @return list<array{role: string, email: string, note: string}>|null
     */
    private function demoAccounts(): ?array
    {
        if (! config('careloop.is_demo')) {
            return null;
        }

        return [
            [
                'role' => '管理者',
                'email' => 'admin@example.com',
                'note' => 'AI利用ログと費用まで含めたすべて',
            ],
            [
                'role' => '生活相談員',
                'email' => 'manager@example.com',
                'note' => 'すべての記録の閲覧と編集',
            ],
            [
                'role' => '介護職員',
                'email' => 'staff@example.com',
                'note' => '自分が書いた記録のみ編集できる',
            ],
        ];
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'status' => $request->session()->get('status'),
            'demoAccounts' => $this->demoAccounts(),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/reset-password', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/forgot-password', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/verify-email', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::registerView(fn () => Inertia::render('auth/register', [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/two-factor-challenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/confirm-password'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('passkeys', function (Request $request) {
            return Limit::perMinute(10)->by(
                ($request->input('credential.id') ?: $request->session()->getId()).'|'.$request->ip(),
            );
        });
    }
}
