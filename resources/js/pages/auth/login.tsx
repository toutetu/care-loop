import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';
import PasskeyVerify from '@/components/passkey-verify';

type DemoAccount = {
    role: string;
    email: string;
    note: string;
};

type Props = {
    status?: string;
    canResetPassword: boolean;
    /** デモ環境でのみ届く。本番相当の環境ではサーバーから渡されない。 */
    demoAccounts: DemoAccount[] | null;
};

/** デモ用アカウントのパスワード。シーダーが作る架空の職員に共通。 */
const DEMO_PASSWORD = 'password';

export default function Login({ status, canResetPassword, demoAccounts }: Props) {
    return (
        <>
            <Head title="ログイン" />

            {demoAccounts && (
                <DemoAccountPanel accounts={demoAccounts} />
            )}

            <PasskeyVerify
                label="パスキーでログイン"
                loadingLabel="確認しています"
                separator="または メールアドレスでログイン"
            />

            <Form
                {...store.form()}
                id="login-form"
                resetOnSuccess={['password']}
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-6">
                            <div className="grid gap-2">
                                <Label htmlFor="email">メールアドレス</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    name="email"
                                    required
                                    autoFocus
                                    tabIndex={1}
                                    autoComplete="email"
                                    placeholder="email@example.com"
                                />
                                <InputError message={errors.email} />
                            </div>

                            <div className="grid gap-2">
                                <div className="flex items-center">
                                    <Label htmlFor="password">パスワード</Label>
                                    {canResetPassword && (
                                        <TextLink
                                            href={request()}
                                            className="ml-auto text-sm"
                                            tabIndex={5}
                                        >
                                            パスワードをお忘れですか？
                                        </TextLink>
                                    )}
                                </div>
                                <PasswordInput
                                    id="password"
                                    name="password"
                                    required
                                    tabIndex={2}
                                    autoComplete="current-password"
                                    placeholder="パスワード"
                                />
                                <InputError message={errors.password} />
                            </div>

                            <div className="flex items-center space-x-3">
                                <Checkbox
                                    id="remember"
                                    name="remember"
                                    tabIndex={3}
                                />
                                <Label htmlFor="remember">ログイン状態を保持する</Label>
                            </div>

                            <Button
                                type="submit"
                                className="mt-4 w-full"
                                tabIndex={4}
                                disabled={processing}
                                data-test="login-button"
                            >
                                {processing && <Spinner />}
                                ログイン
                            </Button>
                        </div>

                        <div className="text-muted-foreground text-center text-sm">
                            アカウントをお持ちでない方は{' '}
                            <TextLink href={register()} tabIndex={5}>
                                新規登録
                            </TextLink>
                        </div>
                    </>
                )}
            </Form>

            {status && (
                <div className="mb-4 text-center text-sm font-medium text-green-600">
                    {status}
                </div>
            )}
        </>
    );
}

/**
 * デモ用アカウントの一覧。
 *
 * 【ログイン画面に直接書く】
 * 見に来た人は、このアプリのことも介護の業務のことも知らない。
 * ログイン情報を探すために別の画面へ戻らせると、そこで離脱する。
 * 押せば入れる状態にしておく。
 *
 * この一覧はデモ環境でしかサーバーから渡ってこない（FortifyServiceProvider）。
 * 本物の事業所で動かすときに、同じ画面が出ることはない。
 */
function DemoAccountPanel({ accounts }: { accounts: DemoAccount[] }) {
    // 入力欄は非制御のため、値を直接入れてからフォームを送信する。
    const signIn = (email: string) => {
        const form = document.getElementById('login-form');
        const emailInput = document.getElementById('email');
        const passwordInput = document.getElementById('password');

        if (
            !(form instanceof HTMLFormElement) ||
            !(emailInput instanceof HTMLInputElement) ||
            !(passwordInput instanceof HTMLInputElement)
        ) {
            return;
        }

        emailInput.value = email;
        passwordInput.value = DEMO_PASSWORD;
        form.requestSubmit();
    };

    return (
        <div className="mb-6 rounded-lg border bg-muted/40 p-4">
            <p className="text-sm font-medium">デモ用のアカウント</p>
            <p className="mt-1 text-xs text-muted-foreground">
                権限による表示の違いを見られるよう、役割ごとに用意しています。
                押すとそのままログインします。
            </p>

            <ul className="mt-3 space-y-2">
                {accounts.map((account) => (
                    <li key={account.email}>
                        <button
                            type="button"
                            onClick={() => signIn(account.email)}
                            className="w-full rounded-md border bg-background px-3 py-2 text-left transition-colors hover:border-primary/40 hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        >
                            <span className="flex flex-wrap items-baseline gap-x-2">
                                <span className="text-sm font-medium">{account.role}</span>
                                <span className="font-mono text-xs text-muted-foreground">
                                    {account.email}
                                </span>
                            </span>
                            <span className="mt-0.5 block text-xs text-muted-foreground">
                                {account.note}
                            </span>
                        </button>
                    </li>
                ))}
            </ul>

            <p className="mt-3 text-xs text-muted-foreground">
                パスワードはいずれも{' '}
                <code className="rounded bg-background px-1.5 py-0.5 font-mono">
                    {DEMO_PASSWORD}
                </code>{' '}
                です。表示されるご利用者・職員・記録はすべて架空のものです。
            </p>
        </div>
    );
}

Login.layout = {
    title: 'CareLoop にログイン',
    description: 'メールアドレスとパスワードを入力してください',
};
