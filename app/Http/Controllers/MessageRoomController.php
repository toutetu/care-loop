<?php

namespace App\Http\Controllers;

use App\Enums\MessageRoomKind;
use App\Http\Requests\StoreMessageGroupRequest;
use App\Models\Message;
use App\Models\MessageAccessLog;
use App\Models\MessageRevision;
use App\Models\MessageRoom;
use App\Models\User;
use App\Support\MessageInbox;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 連絡の部屋を開く・作る。
 */
class MessageRoomController extends Controller
{
    /** 一度に見せる件数。古いものは画面に出さないが、消してはいない。 */
    private const SHOWN = 100;

    /**
     * 管理者の閲覧を1回と数える間隔。
     *
     * 開いているあいだは新着を確かめるために読み直すので、そのたびに
     * 記録すると、1回開いただけで記録が何十件も並ぶ。
     */
    private const ACCESS_LOG_INTERVAL_MINUTES = 30;

    /**
     * 部屋を開く。
     *
     * 参加者が開けば「ここまで読んだ」を残す。管理者が参加していない部屋を
     * 開けば、閲覧の記録を残す。どちらも、開いたという事実に付いてくる
     * 処理なので、画面側のボタンには任せない。
     */
    public function show(Request $request, MessageRoom $messageRoom): Response
    {
        Gate::authorize('view', $messageRoom);

        /** @var User $user */
        $user = $request->user();
        $inbox = new MessageInbox($user);
        $isParticipant = $messageRoom->hasParticipant($user);

        if ($isParticipant) {
            $inbox->markRead($messageRoom);
        } else {
            $this->recordAccess($messageRoom, $user);
        }

        $messageRoom->load('members');

        $messages = $messageRoom->messages()
            ->with(['author', 'revisions'])
            ->latest('id')
            ->limit(self::SHOWN)
            ->get()
            ->reverse()
            ->values();

        return Inertia::render('messages/show', [
            'room' => [
                'id' => $messageRoom->id,
                'kind' => $messageRoom->kind->value,
                'kindLabel' => $messageRoom->kind->label(),
                'title' => $isParticipant
                    ? $inbox->titleOf($messageRoom)
                    : $this->oversightTitle($messageRoom),
                'members' => $messageRoom->isFacilityWide()
                    ? []
                    : $messageRoom->members->pluck('name')->all(),
            ],
            'messages' => $messages->map(fn (Message $message): array => [
                'id' => $message->id,
                'body' => $message->body,
                'author' => $message->author !== null ? $message->author->name : '（退職した職員）',
                'isMine' => $message->user_id === $user->id,
                'canEdit' => $user->can('update', $message),
                'sentAt' => $message->created_at->translatedFormat('n/j H:i'),
                'editedAt' => $message->edited_at?->translatedFormat('n/j H:i'),
                // 直す前の文。誰でも読めるようにしておく。本人にしか見えない
                // 履歴では、直したことを隠せてしまう。
                'revisions' => $message->revisions->map(fn (MessageRevision $revision): array => [
                    'body' => $revision->body,
                    'replacedAt' => $revision->created_at->translatedFormat('n/j H:i'),
                ])->all(),
            ])->all(),
            'canPost' => $user->can('post', $messageRoom),
            'isOversight' => ! $isParticipant,
            'accessLogs' => $messageRoom->accessLogs()
                ->with('viewer')
                ->latest('viewed_at')
                ->limit(10)
                ->get()
                ->map(fn (MessageAccessLog $log): array => [
                    'viewer' => $log->viewer !== null ? $log->viewer->name : '（退職した職員）',
                    'viewedAt' => $log->viewed_at->translatedFormat('n/j H:i'),
                ])->all(),
        ]);
    }

    /**
     * グループを作る。作った人も参加者に入れる。
     *
     * 作った管理者が参加していないと、自分で作ったグループを開くたびに
     * 「管理者の閲覧」として記録されてしまう。
     */
    public function storeGroup(StoreMessageGroupRequest $request): RedirectResponse
    {
        Gate::authorize('createGroup', MessageRoom::class);

        /** @var User $user */
        $user = $request->user();

        $room = DB::transaction(function () use ($request, $user): MessageRoom {
            $room = MessageRoom::query()->create([
                'facility_id' => $user->facility_id,
                'kind' => MessageRoomKind::Group,
                'name' => trim((string) $request->validated('name')),
                'created_by' => $user->id,
            ]);

            /** @var list<int> $memberIds */
            $memberIds = $request->validated('member_ids');
            $room->members()->sync(array_unique([...$memberIds, $user->id]));

            return $room;
        });

        return to_route('messages.show', $room);
    }

    /** 個別の連絡を開く。同じ相手との部屋がすでにあれば、それを開く。 */
    public function direct(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'user_id' => [
                'required',
                'integer',
                Rule::notIn([$user->id]),
                Rule::exists('users', 'id')
                    ->where('facility_id', $user->facility_id)
                    ->where('is_active', true),
            ],
        ], attributes: ['user_id' => '相手の職員']);

        $other = User::query()->whereKey($validated['user_id'])->firstOrFail();

        return to_route('messages.show', MessageRoom::directBetween($user, $other));
    }

    private function recordAccess(MessageRoom $room, User $user): void
    {
        $recent = $room->accessLogs()
            ->where('user_id', $user->id)
            ->where('viewed_at', '>=', now()->subMinutes(self::ACCESS_LOG_INTERVAL_MINUTES))
            ->exists();

        if (! $recent) {
            $room->accessLogs()->create([
                'user_id' => $user->id,
                'viewed_at' => now(),
            ]);
        }
    }

    /** 管理者が見るときの部屋の名前。個別の部屋は2人の名前を並べる。 */
    private function oversightTitle(MessageRoom $room): string
    {
        return $room->kind === MessageRoomKind::Group
            ? (string) $room->name
            : $room->members->pluck('name')->join(' さんと ').' さん';
    }
}
