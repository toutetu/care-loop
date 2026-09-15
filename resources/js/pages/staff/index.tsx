import { Head, Link } from '@inertiajs/react';
import { UserCog, UserPlus } from 'lucide-react';
import { PageHeader } from '@/components/care/page-header';
import { EmptyState, Section } from '@/components/care/section';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import staffRoutes from '@/routes/staff';

type Staff = {
    id: number;
    name: string;
    email: string;
    role: string;
    roleLabel: string;
    isActive: boolean;
    canEdit: boolean;
    isSelf: boolean;
};

type Props = {
    staff: Staff[];
    roles: { value: string; label: string; description: string }[];
};

/**
 * 職員アカウントの一覧（管理者のみ）。
 *
 * 【退職者を削除しない】
 * 在籍の有無で切り替える。削除すると、その職員が書いた記録の記録者が
 * 辿れなくなる。法定保存期間のあいだ、誰が書いたかは残す必要がある。
 */
export default function StaffIndex({ staff, roles }: Props) {
    return (
        <>
            <Head title="職員アカウント" />

            <div className="flex flex-col gap-4 p-4">
                <PageHeader
                    icon={UserCog}
                    title="職員アカウント"
                    meta={
                        <span className="text-muted-foreground text-sm">
                            {staff.filter((row) => row.isActive).length}{' '}
                            名が在籍中
                        </span>
                    }
                    actions={
                        <Button size="sm" asChild>
                            <Link href={staffRoutes.create()}>
                                <UserPlus className="size-4" aria-hidden />
                                職員を追加
                            </Link>
                        </Button>
                    }
                />

                <Section
                    title="役割でできること"
                    description="記録を書ける人を増やす操作なので、追加と変更は管理者に限っています。"
                >
                    <dl className="grid gap-3 sm:grid-cols-3">
                        {roles.map((role) => (
                            <div
                                key={role.value}
                                className="rounded-lg border p-3"
                            >
                                <dt className="text-sm font-medium">
                                    {role.label}
                                </dt>
                                <dd className="text-muted-foreground mt-1 text-xs">
                                    {role.description}
                                </dd>
                            </div>
                        ))}
                    </dl>
                </Section>

                <Section title="職員の一覧">
                    {staff.length === 0 ? (
                        <EmptyState>職員が登録されていません。</EmptyState>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[640px] text-sm">
                                <thead>
                                    <tr className="text-muted-foreground border-b text-left text-xs">
                                        <th className="pb-2 font-medium">
                                            お名前
                                        </th>
                                        <th className="pb-2 font-medium">
                                            メールアドレス
                                        </th>
                                        <th className="pb-2 font-medium">
                                            役割
                                        </th>
                                        <th className="pb-2 font-medium">
                                            在籍
                                        </th>
                                        <th className="pb-2" />
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {staff.map((row) => (
                                        <tr
                                            key={row.id}
                                            className={
                                                row.isActive ? '' : 'opacity-60'
                                            }
                                        >
                                            <td className="py-2 font-medium">
                                                {row.name}
                                                {row.isSelf && (
                                                    <Badge
                                                        variant="outline"
                                                        className="ml-2"
                                                    >
                                                        自分
                                                    </Badge>
                                                )}
                                            </td>
                                            <td className="text-muted-foreground py-2 font-mono text-xs">
                                                {row.email}
                                            </td>
                                            <td className="py-2">
                                                <Badge variant="secondary">
                                                    {row.roleLabel}
                                                </Badge>
                                            </td>
                                            <td className="py-2">
                                                {row.isActive ? (
                                                    '在籍中'
                                                ) : (
                                                    <span className="text-muted-foreground">
                                                        退職
                                                    </span>
                                                )}
                                            </td>
                                            <td className="py-2 text-right">
                                                {row.canEdit ? (
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        asChild
                                                    >
                                                        <Link
                                                            href={staffRoutes.edit(
                                                                row.id,
                                                            )}
                                                        >
                                                            編集
                                                        </Link>
                                                    </Button>
                                                ) : (
                                                    // 自分の役割は変えられない。誤って権限を落とすと
                                                    // 誰も戻せなくなる（UserPolicy）。
                                                    <span className="text-muted-foreground px-3 text-xs">
                                                        {row.isSelf
                                                            ? '自分の役割は変更できません'
                                                            : '—'}
                                                    </span>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Section>
            </div>
        </>
    );
}

StaffIndex.layout = {
    breadcrumbs: [
        { title: 'ダッシュボード', href: dashboard() },
        { title: '職員アカウント', href: staffRoutes.index() },
    ],
};
