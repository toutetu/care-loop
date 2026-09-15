import { Form, Head } from '@inertiajs/react';
import {
    ArrowRight,
    ClipboardList,
    HeartHandshake,
    ShieldCheck,
    UserRound,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import InputError from '@/components/input-error';
import PasskeyVerify from '@/components/passkey-verify';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

type DemoAccount = {
    /** admin / manager / staff。絵柄を選ぶためだけに使う。 */
    key: string;
    role: string;
    email: string;
    note: string;
};

type Props = {
    status?: string;
    canResetPassword: boolean;
    /** 公開デモでは false。新規登録の導線そのものを出さない。 */
    canRegister: boolean;
    /** デモ環境でのみ届く。本番相当の環境ではサーバーから渡されない。 */
    demoAccounts: DemoAccount[] | null;
};

/** デモ用アカウントのパスワード。シーダーが作る架空の職員に共通。 */
const DEMO_PASSWORD = 'password';

export default function Login({
    status,
    canResetPassword,
    canRegister,
    demoAccounts,
}: Props) {
    return (
        <>
            <Head title="ログイン" />

            {demoAccounts && (
                <>
                    <DemoAccountPanel accounts={demoAccounts} />
                    <Divider>アカウントでログイン</Divider>
                </>
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
                                    // デモ環境では役割のボタンが主役なので、
                                    // 入力欄へ勝手にフォーカスを移さない。
                                    autoFocus={!demoAccounts}
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

                            <div className="flex min-h-11 items-center space-x-3">
                                <Checkbox
                                    id="remember"
                                    name="remember"
                                    tabIndex={3}
                                />
                                <Label htmlFor="remember">
                                    ログイン状態を保持する
                                </Label>
                            </div>

                            <Button
                                type="submit"
                                className="mt-2 w-full"
                                // デモのボタンが主役のときは、こちらは控えめにする。
                                variant={demoAccounts ? 'outline' : 'default'}
                                tabIndex={4}
                                pending={processing}
                                data-test="login-button"
                            >
                                ログイン
                            </Button>
                        </div>

                        {canRegister && (
                            <div className="text-muted-foreground text-center text-sm">
                                アカウントをお持ちでない方は{' '}
                                <TextLink href={register()} tabIndex={5}>
                                    新規登録
                                </TextLink>
                            </div>
                        )}
                    </>
                )}
            </Form>

            {status && (
                <div className="text-success-ink mb-4 text-center text-sm font-medium">
                    {status}
                </div>
            )}
        </>
    );
}

/** 役割ごとの絵柄。文言はサーバーが持つので、ここは絵だけを決める。 */
const ROLE_ICONS: Record<string, LucideIcon> = {
    admin: ShieldCheck,
    manager: ClipboardList,
    staff: HeartHandshake,
};

/**
 * デモ用アカウントの一覧。
 *
 * 【ログイン画面に直接書く】
 * 見に来た人は、このアプリのことも介護の業務のことも知らない。
 * ログイン情報を探すために別の画面へ戻らせると、そこで離脱する。
 * 押せば入れる状態にしておく。
 *
 * 【役割のボタンを主役にする】
 * 以前は灰色の箱に灰色のボタンが3つ並び、その下の入力欄と同じ重さに
 * 見えた。見に来た人が最初に押すのはこの3つなので、大きく、絵柄つきで、
 * カードとして浮かせる。通常のログインは下に控えめに置く。
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
        <section aria-labelledby="demo-accounts-heading" className="space-y-3">
            <div className="space-y-1">
                <h2 id="demo-accounts-heading" className="font-bold">
                    役割を選んでデモを見る
                </h2>
                <p className="text-muted-foreground text-sm">
                    押すとそのままログインします。役割によって見えるものが変わります。
                </p>
            </div>

            <ul className="grid gap-2">
                {accounts.map((account) => {
                    const Icon = ROLE_ICONS[account.key] ?? UserRound;

                    return (
                        <li key={account.email}>
                            <button
                                type="button"
                                onClick={() => signIn(account.email)}
                                className="group bg-card hover:border-primary hover:bg-accent/50 focus-visible:ring-ring shadow-card flex w-full items-center gap-3 rounded-xl border px-4 py-3 text-left transition-colors focus-visible:ring-2 focus-visible:outline-none"
                            >
                                <span
                                    className="bg-primary/10 text-primary flex size-11 shrink-0 items-center justify-center rounded-lg"
                                    aria-hidden
                                >
                                    <Icon className="size-6" />
                                </span>
                                <span className="min-w-0 flex-1">
                                    <span className="block font-bold">
                                        {account.role}
                                    </span>
                                    <span className="text-muted-foreground block text-sm">
                                        {account.note}
                                    </span>
                                </span>
                                <ArrowRight
                                    className="text-muted-foreground group-hover:text-primary size-5 shrink-0 transition-colors"
                                    aria-hidden
                                />
                            </button>
                        </li>
                    );
                })}
            </ul>

            <p className="text-muted-foreground text-xs">
                表示されるご利用者・職員・記録はすべて架空のものです。
                パスワードはいずれも{' '}
                <code className="bg-muted rounded px-1.5 py-0.5 font-mono">
                    {DEMO_PASSWORD}
                </code>{' '}
                です。
            </p>
        </section>
    );
}

/** 「または」の区切り線。PasskeyVerify が持つものと同じ見た目。 */
function Divider({ children }: { children: string }) {
    return (
        <div className="relative my-6">
            <div className="absolute inset-0 flex items-center">
                <Separator className="w-full" />
            </div>
            <div className="relative flex justify-center text-xs">
                <span className="bg-background text-muted-foreground px-2">
                    {children}
                </span>
            </div>
        </div>
    );
}

Login.layout = {
    title: 'CareLoop',
    description: '通所介護（デイサービス）の記録・AI支援システム',
};
