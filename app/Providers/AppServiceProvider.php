<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
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
        $this->configureDefaults();
        $this->configureRateLimiting();
    }

    /**
     * AI実行の流量制限。
     *
     * 【月次の上限だけでは足りない】
     * 上限に達すれば請求は止まるが、止まった時点でその月のデモは動かなくなる。
     * 公開しているデモ用アカウントは複数人で共有されるため、一人の連打で
     * 他の人が何も試せなくなる。1回あたりの費用が見えにくい操作ほど、
     * 手前で絞っておく必要がある。
     *
     * 【上限に達したときの表示】
     * Laravel は 429 を返す。職員には何が起きたのか分からないので、
     * 画面にはミドルウェア側の既定ではなく日本語の説明を出す
     * （routes/web.php の throttle 指定）。
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('llm', function (Request $request): array {
            $key = $request->user()->id ?? $request->ip();

            return [
                // 連打の抑止。1回の処理に10秒前後かかるため、分あたり3回で足りる。
                Limit::perMinute(3)->by($key),
                // 1日の総量。全機能あわせて、デモとして試すには十分な回数。
                Limit::perDay(40)->by($key),
            ];
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
