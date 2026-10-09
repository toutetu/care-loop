<?php

namespace App\Http\Middleware;

use App\Enums\ResidentAccessAction;
use App\Models\Resident;
use App\Models\ResidentAccessLog;
use App\Models\ServiceRecord;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ご利用者を1人ずつ開く画面で、閲覧履歴を残す（要件定義 F-19）。
 *
 * 【ルートに付ける】
 * どの画面で残すのかを routes/web.php で一覧できるようにする。
 * コントローラの中に書くと、どの画面が対象なのかを探して回ることになる。
 *
 * 【画面を開けたときだけ残す】
 * 権限で弾かれた要求を「見た」と残すと、漏えいの範囲を実際より広く見積もる。
 *
 * 【部分的な読み直しは残さない】
 * AI処理の実行中、画面は数秒ごとに一部の項目だけを読み直す。これを数えると、
 * 1回開いただけで数十行が積まれ、誰が見たのかが埋もれる。
 */
class RecordResidentAccess
{
    public function handle(Request $request, Closure $next, string $action): Response
    {
        $response = $next($request);

        if (! $response->isSuccessful() || $request->hasHeader('X-Inertia-Partial-Data')) {
            return $response;
        }

        $residentId = $this->residentIdOf($request);

        if ($residentId !== null) {
            ResidentAccessLog::create([
                'user_id' => $request->user()?->id,
                'resident_id' => $residentId,
                'action' => ResidentAccessAction::from($action),
                'ip_address' => $request->ip(),
            ]);
        }

        return $response;
    }

    /** 記録の画面では、その記録のご利用者を見たことになる。 */
    private function residentIdOf(Request $request): ?int
    {
        $resident = $request->route('resident');
        $record = $request->route('serviceRecord');

        return match (true) {
            $resident instanceof Resident => $resident->id,
            $record instanceof ServiceRecord => $record->resident_id,
            default => null,
        };
    }
}
