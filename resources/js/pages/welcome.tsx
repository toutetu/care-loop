import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    FileText,
    FolderGit2,
    FunctionSquare,
    ShieldCheck,
    Sparkles,
    UserCheck,
} from 'lucide-react';
import { REPOSITORY_URL, REQUIREMENTS_URL } from '@/lib/links';
import { dashboard, login } from '@/routes';
import type { Auth } from '@/types';

/**
 * トップページ。このアプリが何であり、何を作ったのかを説明する。
 *
 * 【このページの役割】
 * デモを見る人は、介護の業務にも、このアプリの設計意図にも通じていない。
 * いきなりログイン画面が出ても、何を見ればよいのか分からない。
 * 「どこを見てほしいのか」を先に書く。
 */
export default function Welcome() {
    const { auth } = usePage<{ auth: Auth }>().props;

    return (
        <>
            {/* アプリ名はタイトルの末尾に自動で付く（app.tsx）。ここでは重ねない */}
            <Head title="通所介護の記録とAI活用" />

            <div className="min-h-screen bg-[#FDFDFC] text-[#1b1b18] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]">
                <div className="mx-auto max-w-3xl px-6 py-12 sm:py-16">
                    <header className="flex items-center justify-between gap-4">
                        <p className="text-sm font-medium tracking-wide text-muted-foreground">
                            CareLoop
                        </p>
                        <Link
                            href={auth.user ? dashboard() : login()}
                            className="inline-flex items-center gap-1.5 rounded-md bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white hover:opacity-90 dark:bg-[#EDEDEC] dark:text-[#1b1b18]"
                        >
                            {auth.user ? 'ダッシュボードへ' : 'デモにログイン'}
                            <ArrowRight className="size-4" aria-hidden />
                        </Link>
                    </header>

                    <main className="mt-12 space-y-12">
                        <section>
                            <h1 className="text-2xl font-semibold sm:text-3xl">
                                通所介護（デイサービス）の記録アプリ
                            </h1>
                            <p className="mt-4 leading-relaxed text-muted-foreground">
                                介護の現場で書かれる記録から、リスクの兆候と目標の進捗を読み取り、
                                職員が判断するための材料として提示します。
                                ご家族へお渡しする連絡帳も、同じ記録から作ります。
                            </p>
                            <p className="mt-3 leading-relaxed text-muted-foreground">
                                LaravelからClaude APIを呼び出し、プロンプト設計・構造化出力の検証・
                                エラーハンドリング・費用管理までを実装した個人開発のアプリケーションです。
                            </p>
                        </section>

                        <section className="space-y-4">
                            <h2 className="text-lg font-semibold">設計で考えたこと</h2>

                            <Point
                                icon={FunctionSquare}
                                title="数値の判定はAIに任せない"
                                body="体重の減少率・水分摂取量・バイタルの異常値は、しきい値との比較で決まります。
                                    ここをLLMに投げると、同じ記録でも結果が変わりうる処理になり、根拠も説明できなくなります。
                                    数値はルールベースで算出し、「ふらつきの記述が増えている」のような、
                                    読まないと分からない変化だけをLLMに任せています。
                                    画面ではどちらで検出したのかをバッジで区別して表示します。"
                            />

                            <Point
                                icon={UserCheck}
                                title="AIの出力は下書きとして扱う"
                                body="指摘には必ず根拠となった記録を添えさせ、画面からその記録を開けるようにしています。
                                    根拠を確かめられない出力を、法定文書に転記させないためです。
                                    目標の進捗評価には「判断できる材料が不足」という選択肢を用意し、
                                    記録が足りない期間に無理な評価をさせないようにしています。"
                            />

                            <Point
                                icon={ShieldCheck}
                                title="個人情報は外部へ送らない"
                                body="お名前・ご家族の連絡先・事業所名は、APIへ送る前にプレースホルダへ置換し、
                                    受け取ってから元に戻します。対応表は保存しません。
                                    氏名や既往歴はデータベース上でも暗号化し、カナの検索はブラインドインデックスで行います。
                                    音声は自社サーバーにも外部APIにも送りません。"
                            />

                            <Point
                                icon={Sparkles}
                                title="失敗を隠さない"
                                body="LLMは失敗します。混み合っていることも、求める形で返ってこないこともあります。
                                    失敗の種類を12種に分け、再試行してよいものと設定の見直しが要るものを区別し、
                                    職員には「次に何をすればよいか」が分かる日本語を返します。
                                    呼び出しの結果・所要時間・費用はすべて記録し、画面で確認できます。"
                            />
                        </section>

                        <section>
                            <h2 className="text-lg font-semibold">デモ用のログイン</h2>
                            <p className="mt-2 text-sm text-muted-foreground">
                                架空のご利用者20名・約3ヶ月分の記録が入っています。実在の方とは関係ありません。
                                権限による表示の違いを見られるよう、役割ごとにアカウントを用意しています。
                            </p>

                            <div className="mt-4 overflow-x-auto rounded-lg border">
                                <table className="w-full min-w-[520px] text-sm">
                                    <thead className="bg-muted/50">
                                        <tr className="text-left">
                                            <th className="px-4 py-2 font-medium">役割</th>
                                            <th className="px-4 py-2 font-medium">メールアドレス</th>
                                            <th className="px-4 py-2 font-medium">見られるもの</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        <Account
                                            role="管理者"
                                            email="admin@example.com"
                                            note="AI利用ログと費用まで含めたすべて"
                                        />
                                        <Account
                                            role="生活相談員"
                                            email="manager@example.com"
                                            note="すべての記録の閲覧と編集"
                                        />
                                        <Account
                                            role="介護職員"
                                            email="staff@example.com"
                                            note="自分が書いた記録のみ編集できる"
                                        />
                                    </tbody>
                                </table>
                            </div>

                            <p className="mt-3 text-sm text-muted-foreground">
                                パスワードはいずれも <code className="rounded bg-muted px-1.5 py-0.5">password</code> です。
                            </p>

                            <div className="mt-5 flex flex-wrap gap-3">
                                <Link
                                    href={auth.user ? dashboard() : login()}
                                    className="inline-flex items-center gap-1.5 rounded-md bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white hover:opacity-90 dark:bg-[#EDEDEC] dark:text-[#1b1b18]"
                                >
                                    {auth.user ? 'ダッシュボードへ' : 'デモにログイン'}
                                    <ArrowRight className="size-4" aria-hidden />
                                </Link>
                                <a
                                    href={REQUIREMENTS_URL}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="inline-flex items-center gap-1.5 rounded-md border px-4 py-2 text-sm font-medium hover:bg-accent"
                                >
                                    <FileText className="size-4" aria-hidden />
                                    要件定義書を読む
                                </a>
                                <a
                                    href={REPOSITORY_URL}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="inline-flex items-center gap-1.5 rounded-md border px-4 py-2 text-sm font-medium hover:bg-accent"
                                >
                                    <FolderGit2 className="size-4" aria-hidden />
                                    ソースコード
                                </a>
                            </div>
                        </section>

                        <section>
                            <h2 className="text-lg font-semibold">技術構成</h2>
                            <dl className="mt-3 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                                <Spec label="バックエンド" value="Laravel 13 / PHP 8.4" />
                                <Spec label="フロントエンド" value="Inertia + React 19 + TypeScript" />
                                <Spec label="データベース" value="MariaDB（第3正規形まで正規化）" />
                                <Spec label="LLM" value="Anthropic Claude API（構造化出力）" />
                                <Spec label="静的解析" value="PHPStan level 7 / Laravel Pint" />
                                <Spec label="テスト" value="PHPUnit 198件" />
                            </dl>
                        </section>
                    </main>

                    <footer className="mt-16 border-t pt-6 text-xs text-muted-foreground">
                        個人開発のポートフォリオです。表示されているご利用者・職員・記録はすべて架空のものです。
                    </footer>
                </div>
            </div>
        </>
    );
}

function Point({
    icon: Icon,
    title,
    body,
}: {
    icon: typeof FunctionSquare;
    title: string;
    body: string;
}) {
    return (
        <div className="flex gap-3 rounded-lg border p-4">
            <Icon className="mt-0.5 size-5 shrink-0 text-muted-foreground" aria-hidden />
            <div>
                <h3 className="font-medium">{title}</h3>
                <p className="mt-1 text-sm leading-relaxed text-muted-foreground">{body}</p>
            </div>
        </div>
    );
}

function Account({ role, email, note }: { role: string; email: string; note: string }) {
    return (
        <tr>
            <td className="px-4 py-2 font-medium whitespace-nowrap">{role}</td>
            <td className="px-4 py-2 font-mono text-xs">{email}</td>
            <td className="px-4 py-2 text-muted-foreground">{note}</td>
        </tr>
    );
}

function Spec({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex gap-2">
            <dt className="w-28 shrink-0 text-muted-foreground">{label}</dt>
            <dd>{value}</dd>
        </div>
    );
}
