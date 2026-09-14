<?php

namespace App\Http\Controllers;

use App\Models\ServiceRecord;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

/**
 * 日次の連絡帳（F-16）。
 *
 * 【なぜ月次とは別に日次が必要か】
 * 通所介護の連絡帳は本来「毎回」お渡しするものである。
 * 月次のまとめだけでは、ご家族はその日の様子を知る手段がない。
 * また、体調の変化に気づいたときにすぐ共有できないと、
 * 気づきが翌月まで届かないことになる。
 *
 * 【承認フローを月次と分けている理由】
 * 月次報告は管理者の承認を経てから出力する。
 * 一方この連絡帳は、職員が記録を確認して確定した時点（confirmed_at）で
 * 出力できるようにしている。
 * ご利用者20名分の連絡帳に毎日管理者承認を挟むと、送迎に間に合わない。
 * 現場が回らない統制は、結局は運用されなくなる。
 *
 * 【AIが動かなくても出力できる】
 * ご様子の文章（family_text）が未生成でも、バイタルと食事・水分は印刷できる。
 * LLMの障害でご家族への連絡が止まってはいけないため（要件定義 8章 可用性）。
 */
class DailyFamilyReportController extends Controller
{
    public function show(ServiceRecord $serviceRecord): View
    {
        Gate::authorize('print', $serviceRecord);

        $serviceRecord->load([
            'resident.facility',
            'resident.careLevel',
            'recorder',
            'vitalSigns',
            'mealRecords',
        ]);

        return view('reports.daily-family', [
            'record' => $serviceRecord,
            'resident' => $serviceRecord->resident,
            'facility' => $serviceRecord->resident->facility,
            'vital' => $serviceRecord->vitalSigns->first(),
            'lunch' => $serviceRecord->mealRecords->firstWhere('meal_type', 'lunch'),
            // 口頭でお伝えする事項は連絡帳にも載せる。
            // 「文書に書いたうえで、口頭でも伝える」という方針のため
            // （要件定義 7.3節 設計判断の変更履歴）。
            'verbalContacts' => $serviceRecord->verbalContactTasks()->pending()->get(),
        ]);
    }
}
