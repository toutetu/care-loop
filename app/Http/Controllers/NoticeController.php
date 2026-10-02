<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\NoticeBoard;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * お知らせ。スマートフォンの下のバーから開く画面。
 *
 * 【ダッシュボードと別の画面にした理由】
 * ダッシュボードは朝礼で全体を見る場所で、件数やAIの費用まで並ぶ。
 * 介助の合間に片手で開いたときに知りたいのは「いま気をつけることは何か」
 * だけである。同じ中身から、すぐ読めるものだけを抜き出して見せる。
 */
class NoticeController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $notices = new NoticeBoard($user->facility_id);

        return Inertia::render('notices/index', [
            'risks' => $notices->urgentRisks(),
            'verbalContacts' => $notices->pendingVerbalContacts(),
        ]);
    }
}
