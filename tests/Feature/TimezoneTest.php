<?php

namespace Tests\Feature;

use App\Models\Resident;
use App\Models\ServiceRecord;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * 記録の時刻。
 *
 * サービス提供記録は法定の保存文書であり、時刻はその一部である。
 * 9時間ずれていても画面は普通に表示されるため、誰も気づかないまま
 * 誤った記録が残り続ける。設定の誤りを黙って通さない。
 */
class TimezoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_日本時間で動く(): void
    {
        // 日本の介護事業所でのみ使うアプリケーションで、UTCで動いて得をする
        // 場面がない。環境変数を設定し忘れた環境でもずれないよう、既定を
        // Asia/Tokyo にしている。
        $this->assertSame('Asia/Tokyo', config('app.timezone'));
        $this->assertSame('Asia/Tokyo', date_default_timezone_get());
    }

    public function test_保存した時刻が日本時間で読み戻せる(): void
    {
        $record = ServiceRecord::factory()
            ->for(Resident::factory())
            ->create(['confirmed_at' => '2026-09-14 15:40:00']);

        $this->assertSame(
            '2026-09-14 15:40',
            $record->refresh()->confirmed_at->format('Y-m-d H:i'),
        );
    }

    public function test_存在しないタイムゾーンでは起動しない(): void
    {
        // PHP は不正な名前を渡されても例外を投げず、UTC のまま動き続ける。
        // 一文字の打ち間違いで記録が9時間ずれることになる。
        config(['app.timezone' => 'Asia/Tokyo-typo']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('APP_TIMEZONE');

        (new AppServiceProvider($this->app))->boot();
    }

    public function test_正しいタイムゾーンなら起動を妨げない(): void
    {
        config(['app.timezone' => 'UTC']);

        (new AppServiceProvider($this->app))->boot();

        $this->assertTrue(true);
    }
}
