<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\MessagingTerms;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 連絡を使い始めるときの承諾。
 *
 * 文面はサーバーから渡す。画面に文面を書くと、版（MessagingTerms::VERSION）と
 * 実際に見せた文面が食い違っても気づけない。
 */
class MessageConsentController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        // 承諾済みの人がブックマークなどから来たら、そのまま一覧へ通す
        if (MessagingTerms::acceptedBy($user)) {
            return to_route('messages.index');
        }

        return Inertia::render('messages/consent', [
            'version' => MessagingTerms::VERSION,
            'points' => MessagingTerms::POINTS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        MessagingTerms::accept($user);

        return to_route('messages.index');
    }
}
