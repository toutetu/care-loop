<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMessageRequest;
use App\Models\Message;
use App\Models\MessageRoom;
use App\Models\User;
use App\Support\MessageInbox;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * メッセージを送る・直す。消す手段は用意しない（Message）。
 */
class MessagePostController extends Controller
{
    public function store(StoreMessageRequest $request, MessageRoom $messageRoom): RedirectResponse
    {
        Gate::authorize('post', $messageRoom);

        /** @var User $user */
        $user = $request->user();

        $messageRoom->messages()->create([
            'user_id' => $user->id,
            'body' => trim((string) $request->validated('body')),
        ]);

        // 自分が送ったところまでは読んだことにする。送った直後の部屋に
        // 未読の印が付くと、読み落としがあるように見える。
        (new MessageInbox($user))->markRead($messageRoom);

        return back();
    }

    /**
     * 直す。直す前の文は残す（Message::editBody）。
     */
    public function update(StoreMessageRequest $request, MessageRoom $messageRoom, Message $message): RedirectResponse
    {
        // 別の部屋のメッセージを、この部屋の URL で直させない
        abort_unless($message->message_room_id === $messageRoom->id, 404);

        Gate::authorize('update', $message);

        $message->editBody(trim((string) $request->validated('body')));

        return back()->with('success', 'メッセージを直しました。直す前の文も残っています。');
    }
}
