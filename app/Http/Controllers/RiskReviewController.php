<?php

namespace App\Http\Controllers;

use App\Models\RiskAssessment;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * リスク兆候の抽出結果を「確認済み」にする。
 *
 * 【抽出結果ごとにまとめて確認する】
 * 指摘を1件ずつ確認させると、同じ記録を根拠にした指摘を何度も開くことになる。
 * 利用者の画面には最新の抽出結果がまとめて並ぶので、それを読み終えたところで
 * 1回押せば足りるようにする。
 *
 * 【取り消しは用意しない】
 * 確認したあとで新しい記録が増えたなら、抽出し直せば新しい結果として出る。
 * 確認を取り消せると、誰がいつ確かめたのかが曖昧になる。
 */
class RiskReviewController extends Controller
{
    public function store(Request $request, RiskAssessment $riskAssessment): RedirectResponse
    {
        Gate::authorize('review', $riskAssessment);

        /** @var User $user */
        $user = $request->user();

        // 二度押しで確認した人と日時が上書きされないよう、最初の1回だけ残す
        if (! $riskAssessment->isReviewed()) {
            $riskAssessment->markReviewed($user);
        }

        return back()->with('success', '確認済みにしました。お知らせとダッシュボードから外れます。');
    }
}
