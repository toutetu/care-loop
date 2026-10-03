<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAnnouncementRequest;
use App\Models\Announcement;
use App\Models\AnnouncementRead;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 管理者からの周知。
 *
 * 【誰がまだ確認していないかを、全員に見せる】
 * 管理者だけが見られる名簿にすると、未確認の人に声をかけられるのは管理者
 * だけになる。同じ日に出勤している職員が「まだ見ていないなら伝えておく」と
 * 動けるよう、確認の状況は職員全員に見せる。
 */
class AnnouncementController extends Controller
{
    /** 一覧に出す件数。古い周知も消してはいない。 */
    private const SHOWN = 50;

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $announcements = Announcement::query()
            ->where('facility_id', $user->facility_id)
            ->with(['author', 'reads.user'])
            ->latest('id')
            ->limit(self::SHOWN)
            ->get();

        $colleagues = User::query()
            ->where('facility_id', $user->facility_id)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        return Inertia::render('announcements/index', [
            'announcements' => $announcements
                ->map(fn (Announcement $announcement): array => $this->row($announcement, $user, $colleagues))
                ->all(),
            'canPost' => $user->can('create', Announcement::class),
        ]);
    }

    public function store(StoreAnnouncementRequest $request): RedirectResponse
    {
        Gate::authorize('create', Announcement::class);

        /** @var User $user */
        $user = $request->user();

        Announcement::query()->create([
            'facility_id' => $user->facility_id,
            'user_id' => $user->id,
            'title' => trim((string) $request->validated('title')),
            'body' => trim((string) $request->validated('body')),
            'is_important' => $request->boolean('is_important'),
        ]);

        return to_route('announcements.index')
            ->with('success', '周知を出しました。職員が「確認しました」を押すと、ここに名前が並びます。');
    }

    /** 「確認しました」を押す。 */
    public function confirm(Request $request, Announcement $announcement): RedirectResponse
    {
        Gate::authorize('confirm', $announcement);

        /** @var User $user */
        $user = $request->user();

        $announcement->confirm($user);

        return back();
    }

    /**
     * @param  Collection<int, User>  $colleagues
     * @return array<string, mixed>
     */
    private function row(Announcement $announcement, User $viewer, Collection $colleagues): array
    {
        $confirmed = $announcement->reads->keyBy('user_id');

        // 確認してほしい相手は、在籍中の職員から書いた本人を除いた全員
        $targets = $colleagues->reject(fn (User $colleague): bool => $colleague->id === $announcement->user_id);

        return [
            'id' => $announcement->id,
            'title' => $announcement->title,
            'body' => $announcement->body,
            'isImportant' => $announcement->is_important,
            'author' => $announcement->author !== null ? $announcement->author->name : '（退職した職員）',
            'postedAt' => $announcement->created_at->translatedFormat('n/j H:i'),
            'isMine' => $announcement->user_id === $viewer->id,
            'confirmedAt' => $this->confirmedAt($confirmed->get($viewer->id)),
            'targetCount' => $targets->count(),
            'confirmed' => $targets
                ->filter(fn (User $colleague): bool => $confirmed->has($colleague->id))
                ->map(fn (User $colleague): array => [
                    'name' => $colleague->name,
                    'at' => $this->confirmedAt($confirmed->get($colleague->id)),
                ])
                ->values()
                ->all(),
            'unconfirmed' => $targets
                ->reject(fn (User $colleague): bool => $confirmed->has($colleague->id))
                ->pluck('name')
                ->values()
                ->all(),
        ];
    }

    private function confirmedAt(?AnnouncementRead $read): ?string
    {
        return $read?->confirmed_at->translatedFormat('n/j H:i');
    }
}
