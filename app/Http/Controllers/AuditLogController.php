<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 編集履歴（管理者のみ）。
 *
 * 【なぜ必要か】
 * ご利用者情報と職員アカウントは、誰がいつ何を変えたのかを後から辿れる
 * 必要がある。要介護度や既往歴の書き換えは、記録の読み方そのものを変える。
 * 職員の役割変更は、誰が何を編集できるかを変える。
 * 変更した本人の記憶に頼る運用は成り立たない。
 *
 * 【管理者に限る理由】
 * 履歴には変更前の値が含まれる。ご利用者の旧住所や旧連絡先まで見えるため、
 * 日々の介護業務で開く必要はない。閲覧できる人を絞ることが保護になる。
 *
 * 【事業所で絞る】
 * 他の事業所のご利用者・職員の履歴は見えてはいけない。
 * 対象が削除されている場合もあるため、所属の判定は取得後に行う。
 */
class AuditLogController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', AuditLog::class);

        /** @var User $user */
        $user = $request->user();

        $type = $request->string('type')->value();

        $paginator = AuditLog::query()
            ->with(['user', 'auditable'])
            ->when($type === 'residents', fn ($query) => $query->where('auditable_type', (new Resident)->getMorphClass()))
            ->when($type === 'staff', fn ($query) => $query->where('auditable_type', (new User)->getMorphClass()))
            ->latest('created_at')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('audit-logs/index', [
            'type' => in_array($type, ['residents', 'staff'], true) ? $type : 'all',
            'logs' => [
                'data' => array_values(collect($paginator->items())
                    // 他の事業所の分は渡さない。DB側で絞れないのは、
                    // 対象がご利用者と職員のどちらにもなりうるためである。
                    ->filter(fn (AuditLog $log) => $this->belongsToFacility($log, $user))
                    ->map(fn (AuditLog $log): array => $this->present($log))
                    ->all()),
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * この履歴が自分の事業所のものか。
     *
     * 対象が削除済みで辿れない場合は見せない。どの事業所のものか
     * 判断できないものを、念のため表示する理由はない。
     */
    private function belongsToFacility(AuditLog $log, User $user): bool
    {
        $subject = $log->auditable;

        return match (true) {
            $subject instanceof Resident => $subject->facility_id === $user->facility_id,
            $subject instanceof User => $subject->facility_id === $user->facility_id,
            default => false,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function present(AuditLog $log): array
    {
        $subject = $log->auditable;

        return [
            'id' => $log->id,
            'event' => $log->event,
            'eventLabel' => $log->event === 'created' ? '登録' : '変更',
            'editor' => $log->user->name ?? '（削除された職員）',
            'at' => $log->created_at->translatedFormat('Y年n月j日 H:i'),
            'ipAddress' => $log->ip_address,
            ...$this->subjectOf($subject),
            'changes' => $this->changesOf($log, $subject),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function subjectOf(?object $subject): array
    {
        return match (true) {
            $subject instanceof Resident => [
                'subjectKind' => 'resident',
                'subjectKindLabel' => 'ご利用者',
                'subjectName' => "{$subject->name} 様",
                'subjectId' => $subject->id,
            ],
            $subject instanceof User => [
                'subjectKind' => 'staff',
                'subjectKindLabel' => '職員',
                'subjectName' => $subject->name,
                'subjectId' => $subject->id,
            ],
            default => [
                'subjectKind' => 'unknown',
                'subjectKindLabel' => '不明',
                'subjectName' => '（削除済み）',
                'subjectId' => null,
            ],
        };
    }

    /**
     * 変更内容を読める形にする。
     *
     * 列名のまま出しても、誰が読んでも分かるわけではない。
     * 「何が変わったのか」を確かめる画面なので、画面と同じ言葉で出す。
     *
     * @return list<array<string, mixed>>
     */
    private function changesOf(AuditLog $log, ?object $subject): array
    {
        $labels = method_exists($subject, 'auditLabels') ? $subject->auditLabels() : [];
        $rows = [];

        foreach ($log->changes ?? [] as $column => $change) {
            $rows[] = [
                'label' => $labels[$column] ?? $column,
                'before' => $this->readable($change['before'] ?? null),
                'after' => $this->readable($change['after'] ?? null),
            ];
        }

        return $rows;
    }

    /**
     * 値を画面の文字へ。
     *
     * 空欄は「（未入力）」と書く。何も書かないと、値が消えたのか
     * 表示できていないのかが区別できない。
     */
    private function readable(mixed $value): string
    {
        return match (true) {
            $value === null, $value === '' => '（未入力）',
            is_bool($value) => $value ? 'はい' : 'いいえ',
            is_array($value) => implode('、', array_map(strval(...), $value)),
            default => (string) $value,
        };
    }
}
