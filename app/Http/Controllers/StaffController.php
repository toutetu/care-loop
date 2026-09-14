<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\StoreUserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 職員アカウントの管理（管理者のみ）。
 *
 * 【なぜ管理者に限るのか】
 * アカウントを作れるということは、記録を書ける人を増やせるということである。
 * 介護記録は法定の保存文書であり、誰が書いたかを追えることが前提になる。
 *
 * 【退職者を削除しない】
 * 在籍の有無（is_active）で切り替える。削除すると、その職員が書いた記録の
 * 記録者が辿れなくなる。法定保存期間のあいだ、誰が書いたかは残す必要がある。
 */
class StaffController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', User::class);

        /** @var User $viewer */
        $viewer = $request->user();

        $staff = User::query()
            ->where('facility_id', $viewer->facility_id)
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        return Inertia::render('staff/index', [
            'staff' => array_values($staff->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
                'roleLabel' => $user->role->label(),
                'isActive' => $user->is_active,
                // 自分自身の役割は変えられない。誤って権限を落とすと
                // 誰も戻せなくなる（UserPolicy）。
                'canEdit' => $viewer->can('update', $user),
                'isSelf' => $user->id === $viewer->id,
            ])->all()),
            'roles' => $this->roles(),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', User::class);

        return Inertia::render('staff/form', [
            'staff' => null,
            'roles' => $this->roles(),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        Gate::authorize('create', User::class);

        /** @var User $viewer */
        $viewer = $request->user();

        $data = $request->validated();

        $user = new User;
        $user->facility_id = $viewer->facility_id;
        $user->fill([
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => $data['role'],
            'password' => $data['password'],
            'is_active' => $data['is_active'] ?? true,
        ]);
        // メール送信の手配がないため、確認済みとして登録する。
        // 実運用では招待メールからの確認に差し替える必要がある。
        $user->email_verified_at = now();
        $user->save();

        return to_route('staff.index')
            ->with('success', "{$user->name} さんのアカウントを作成しました。");
    }

    public function edit(User $user): Response
    {
        Gate::authorize('update', $user);

        return Inertia::render('staff/form', [
            'staff' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
                'is_active' => $user->is_active,
            ],
            'roles' => $this->roles(),
        ]);
    }

    public function update(StoreUserRequest $request, User $user): RedirectResponse
    {
        Gate::authorize('update', $user);

        $data = $request->validated();

        $user->fill([
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => $data['role'],
            'is_active' => $data['is_active'] ?? false,
        ]);

        // 空欄なら据え置く。変更のたびにパスワードを入れ直させると、
        // 覚えやすいものに変えられてしまう。
        if (($data['password'] ?? '') !== '') {
            $user->password = $data['password'];
        }

        $user->save();

        return to_route('staff.index')
            ->with('success', "{$user->name} さんの情報を更新しました。");
    }

    /**
     * 役割の選択肢。
     *
     * @return list<array<string, string>>
     */
    private function roles(): array
    {
        return array_map(fn (UserRole $role): array => [
            'value' => $role->value,
            'label' => $role->label(),
            'description' => match ($role) {
                UserRole::Staff => '自分が書いた記録のみ編集できます',
                UserRole::Manager => 'すべての記録とご利用者情報を編集できます',
                UserRole::Admin => '職員アカウントとAI利用ログも扱えます',
            },
        ], UserRole::cases());
    }
}
