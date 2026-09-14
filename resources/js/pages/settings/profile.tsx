import { Form, Head, Link, usePage } from '@inertiajs/react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';
import type { Auth } from '@/types';

type PageProps = {
    auth: Auth;
};

/**
 * プロフィールの設定。
 *
 * 【アカウントの削除を置いていない】
 * 職員が書いた記録は法定の保存文書であり、記録者が誰かを辿れる必要がある。
 * 本人が自分のアカウントを消せると、その手がかりが失われる。
 *
 * 退職時は管理者が在籍を外す（職員アカウントの画面）。アカウント自体は残る。
 */
export default function Profile({
    mustVerifyEmail,
    status,
}: {
    mustVerifyEmail: boolean;
    status?: string;
}) {
    const { auth } = usePage<PageProps>().props;

    return (
        <>
            <Head title="プロフィール設定" />

            <h1 className="sr-only">プロフィール設定</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="プロフィール"
                    description="お名前とメールアドレスを変更できます"
                />

                <Form
                    {...ProfileController.update.form()}
                    options={{
                        preserveScroll: true,
                    }}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">お名前</Label>

                                <Input
                                    id="name"
                                    className="mt-1 block w-full"
                                    defaultValue={auth.user.name}
                                    name="name"
                                    required
                                    autoComplete="name"
                                    placeholder="山口 みどり"
                                />

                                {/* 記録の「記入者」として連絡帳にも印刷される */}
                                <p className="text-muted-foreground text-xs">
                                    記録の記入者として、ご家族へお渡しする連絡帳にも表示されます。
                                </p>

                                <InputError
                                    className="mt-2"
                                    message={errors.name}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="email">メールアドレス</Label>

                                <Input
                                    id="email"
                                    type="email"
                                    className="mt-1 block w-full"
                                    defaultValue={auth.user.email}
                                    name="email"
                                    required
                                    autoComplete="username"
                                    placeholder="staff@example.com"
                                />

                                <p className="text-muted-foreground text-xs">
                                    ログインに使います。
                                </p>

                                <InputError
                                    className="mt-2"
                                    message={errors.email}
                                />
                            </div>

                            {mustVerifyEmail &&
                                auth.user.email_verified_at === null && (
                                    <div>
                                        <p className="text-muted-foreground -mt-4 text-sm">
                                            メールアドレスの確認が済んでいません。{' '}
                                            <Link
                                                href={send()}
                                                as="button"
                                                className="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                                            >
                                                確認メールを再送する
                                            </Link>
                                        </p>

                                        {status ===
                                            'verification-link-sent' && (
                                            <div className="text-success-ink mt-2 text-sm font-medium">
                                                確認用のリンクをメールでお送りしました。
                                            </div>
                                        )}
                                    </div>
                                )}

                            <div className="flex items-center gap-4">
                                <Button
                                    disabled={processing}
                                    data-test="update-profile-button"
                                >
                                    保存する
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

Profile.layout = {
    breadcrumbs: [
        {
            title: 'プロフィール設定',
            href: edit(),
        },
    ],
};
