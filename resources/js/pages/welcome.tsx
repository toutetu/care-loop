import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    BookOpen,
    ClipboardList,
    Eye,
    FileText,
    FolderGit2,
    HeartHandshake,
    MessagesSquare,
    Mic,
    Palette,
    ScrollText,
    ShieldCheck,
    Smartphone,
    Sparkles,
    Target,
    UserCheck,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { BrandMark } from '@/components/brand-mark';
import { ChallengeSolutionList } from '@/components/intro/challenge-solution';
import type { ChallengeSolution } from '@/components/intro/challenge-solution';
import { FlowFigure } from '@/components/intro/flow-figure';
import { IconCard } from '@/components/intro/icon-card';
import { IntroSection } from '@/components/intro/intro-section';
import { Screenshot } from '@/components/intro/screenshot';
import { Button } from '@/components/ui/button';
import {
    DATABASE_DESIGN_URL,
    DEPLOY_GUIDE_URL,
    DESIGN_GUIDE_URL,
    REPOSITORY_URL,
    REQUIREMENTS_URL,
    SCALE_POLICY_URL,
} from '@/lib/links';
import { dashboard, login } from '@/routes';
import type { Auth } from '@/types';

/**
 * トップページ。この作品が「なぜ・どう・何を」作ったのかを説明する。
 *
 * 【このページの役割】
 * 見に来る人は採用担当者で、介護の業務にも、このアプリの設計意図にも
 * 通じていない。クローンして動かすこともしない。いきなりログイン画面が
 * 出ても、何を見ればよいのか分からない。
 *
 * 【構成の順番】
 * 背景（作者が現場で見た課題）→ 解決の考え方 → AI の使い方 → UI →
 * デモ → 技術構成。README は「何を作ったか」から始まるが、
 * ここでは「なぜ作ったか」を先に置く。読む人が最初に知りたいのは
 * 作者がどんな課題を持っていたかであり、技術はその答えとして読まれる。
 *
 * 【文章は三人称】
 * 「作者は」で書く。職務経歴書と一緒に読まれる前提で、履歴書側の
 * 一人称と混ざらないようにしている。
 *
 * 文言の出典は docs/01_要件定義書.md（1章・2章・7章・F-18）と README。
 * 数字（テスト件数・エラー種別の数）は実装に合わせて手で直す。
 */

const NAV = [
    { href: '#background', label: '背景' },
    { href: '#approach', label: '解決の考え方' },
    { href: '#ai', label: 'AIの使い方' },
    { href: '#ui', label: 'UI' },
    { href: '#demo', label: 'デモ' },
    { href: '#tech', label: '技術' },
];

/* ------------------------------------------------------------------ *
 * 01 背景と課題
 * ------------------------------------------------------------------ */

const PROBLEMS: {
    icon: LucideIcon;
    eyebrow: string;
    title: string;
    body: string;
}[] = [
    {
        icon: FileText,
        eyebrow: '課題 1',
        title: '記録は「書く」が「読まれない」',
        body: '通所介護では法律で記録の作成と保存が義務づけられ、毎日ご利用者1人あたり複数の記録が発生します。しかし多くは書いて終わりになり、1ヶ月分を通読しなければ気づけない変化（少しずつ食事量が減っている、ふらつきの記述が増えている）は、日々の業務の中で見落とされていました。',
    },
    {
        icon: ClipboardList,
        eyebrow: '課題 2',
        title: 'モニタリングが属人的で重い',
        body: '通所介護計画書の目標は定期的に振り返る必要があります。しかし過去1〜3ヶ月分の記録を人手で読み返して要約する作業になり、担当者の記憶と主観に頼ることになります。結果として「特変なし」が並ぶ、形骸化した記録になりがちでした。',
    },
    {
        icon: MessagesSquare,
        eyebrow: '課題 3',
        title: '申し送りで情報が落ちる',
        body: '送迎・入浴・機能訓練と担当が分かれています。口頭とメモが中心の申し送りでは、次の担当者に必要な情報が途中で欠けていました。',
    },
    {
        icon: HeartHandshake,
        eyebrow: '課題 4',
        title: 'ご家族への報告が後回しになる',
        body: 'ご家族は「今日どう過ごしたか」を知りたいのに、報告文の作成は職員にとって最も後回しになりやすい業務です。「お変わりありませんでした」という定型文になり、信頼につながりませんでした。',
    },
];

/* ------------------------------------------------------------------ *
 * 02 解決の考え方
 * ------------------------------------------------------------------ */

const PRINCIPLES = [
    'LLM の出力は必ず人が確認・編集してから確定する。生成物をそのまま保存・送信しない',
    '指摘には根拠となる記録を必ず添えさせる。作り話（ハルシネーション）を検証できるようにする',
    '診断・医療判断はさせない。「〜の疑い」ではなく「〜の記述が増えている」という事実の提示に留める',
    'ご家族向けの文書は職員の確認を経て確定する',
];

/* ------------------------------------------------------------------ *
 * 03 AI をどう使ったか
 * ------------------------------------------------------------------ */

const AI_USES: {
    icon: LucideIcon;
    eyebrow: string;
    title: string;
    body: string;
}[] = [
    {
        icon: Mic,
        eyebrow: 'F-LLM-05',
        title: '音声入力の三面変換',
        body: '口語の原文から、記録用・ご家族向け・申し送り用の3つの文章と、水分量などの構造化データを、1回の呼び出しで生成します。',
    },
    {
        icon: Sparkles,
        eyebrow: 'F-LLM-02',
        title: 'リスク兆候の抽出',
        body: '数値で決まる指標はルールで先に算出し、その結果を渡したうえで、記述を読まないと分からない変化だけを抽出させます。',
    },
    {
        icon: Target,
        eyebrow: 'F-LLM-01',
        title: '目標進捗の要約',
        body: '短期目標ごとに、期間中の記録から進捗を評価します。材料が足りなければ「判断できる材料が不足」を選ばせます。',
    },
];

const AI_CHALLENGES: ChallengeSolution[] = [
    {
        challenge:
            '数値の判定まで任せると、同じ記録でも結果が変わり、なぜその結論になったのかを数値で説明できない。',
        solution:
            '体重の減少率・水分摂取量・バイタルの異常値はルールベースで決定的に算出し、LLM は記述からの質的な変化に限定する。画面ではどちらで検出したのかをバッジで区別する。',
    },
    {
        challenge:
            '形式（JSON スキーマ）に合っていても、根拠のない指摘が混ざる。',
        solution:
            '指摘には根拠の記録を必ず添えさせ、受け取った値はスキーマ検証のあとに再検証する。進捗評価には「材料不足」の選択肢を用意する。AI が書き直した文章は確定が外れ、職員が書き換えると人の判断として記録される。',
    },
    {
        challenge:
            'お名前や既往歴といった要配慮個人情報が、外部の API へ出ていく。',
        solution:
            '送信前にプレースホルダへ置換し、受け取ってから元に戻す。対応表は保存しない。データベース上でも暗号化し、カナの検索はブラインドインデックスで行う。音声は外部へ送らず、OS 標準の音声入力で文字にしてから扱う。',
    },
    {
        challenge:
            '応答に数十秒かかる。HTTP リクエストの中で待つと手前のゲートウェイに切られ、英語のエラーページだけが残る（本番で実際に起きた）。',
        solution:
            'AI 処理はキューで実行し、画面は実行状況をポーリングして結果を受け取る。処理する仕組みが止まっているときも「押したのに何も起きない」状態にならないよう、待機が長引けば警告を出し、一定時間で失敗として扱う。',
    },
    {
        challenge:
            '失敗の種類が多い。混み合っている、求める形で返ってこない、認証が通らない、上限に達した。',
        solution:
            '失敗を 15 種に分類し、再試行してよいものと設定の見直しが要るものを区別する。職員には「次に何をすればよいか」が分かる日本語を返す。',
    },
    {
        challenge:
            '費用が読めない。ログイン情報を公開したデモでは、誰でも実 API を呼べる。',
        solution:
            '呼び出しごとに所要時間・トークン数・費用を記録し、月次の上限と流量制限（1分3回・1日40回）で止める。成功だけでなく失敗も種別ごとに集計して見せる。',
    },
    {
        challenge:
            'API 側の JSON スキーマ検証が仕様どおりではない。object のすべてに additionalProperties: false を書かないと 400 になる。',
        solution:
            '送信直前に機械的に正規化する層を置き、プロンプトの書き手が覚えていなくても通るようにする。本番の全スキーマに正規化が効いていることをテストで確かめる。',
    },
    {
        challenge: 'API キーと課金がなければ、動作確認もテストもできない。',
        solution:
            'LLM 呼び出しをインターフェースで抽象化し、キー未設定時とテストでは固定 JSON を返す実装に差し替える。CI は課金ゼロで異常系まで通る。',
    },
];

/* ------------------------------------------------------------------ *
 * 04 UI で重視したこと
 * ------------------------------------------------------------------ */

const UI_FOCUS: { icon: LucideIcon; title: string; body: string }[] = [
    {
        icon: Eye,
        title: '文字とコントラスト',
        body: '職員は 50〜60 代が中心です。文字の尺度を 1 段大きくし、色つきの文字はすべて 7:1（WCAG AAA）以上にしています。加齢でコントラスト感度が落ちるため、AA では足りません。',
    },
    {
        icon: Smartphone,
        title: '片手で、その場で、数十秒で',
        body: '主端末は iPhone。主要な操作を画面下 3 分の 1 に置き、タップ領域は 44px 以上。1 つの記録を 1 画面で完結させ、画面遷移を挟みません。',
    },
    {
        icon: Palette,
        title: 'AI と人の判断を見分ける',
        body: '色を「対応の要否」と「情報の出どころ」の 2 軸に分け、AI の出力には必ず未確認の印を付けます。人が確かめたものと同じ見た目にはしません。',
    },
    {
        icon: Mic,
        title: '書く時間がない前提',
        body: 'OS 標準の音声入力を起点にし、整形は AI が行います。話した原文は書き換えずに残し、AI が何を変えたのかを後から追えるようにします。',
    },
];

const UI_CHALLENGES: ChallengeSolution[] = [
    {
        challenge:
            'もう片方の手でご利用者を支えていて、画面左上の角に親指が届かない。',
        solution:
            '下部タブバーに「ホーム・記録・ご利用者・その他」を置く。上部は情報の表示だけにし、左上のハンバーガーは出さない。',
    },
    {
        challenge: '手袋や濡れた手で、隣のボタンを押してしまう。',
        solution:
            'タップ領域は最小 44×44px、隣との間隔は 8px 以上。小さいボタンも高さは下げず、横幅だけ詰める。',
    },
    {
        challenge:
            '14px の日本語は漢字の細部が潰れる。送迎中は屋外の明るさで読む。',
        solution:
            '文字の尺度を底上げし（16px を最小に）、色つきの文字は比率を測って 7:1 以上で決める。副次効果として、iOS Safari で入力欄に触れたときの自動ズームも止まる。',
    },
    {
        challenge: '色の意味を覚えきれない。',
        solution:
            '赤・黄・緑は「対応の要否」、紫・灰は「情報の出どころ」。軸をまたいで同じ色を使わない。ルールと AI の両方で検出したものは 3 色目を作らず、2 枚のバッジを並べる。',
    },
    {
        challenge: 'AI が書いた文章を、読まないまま確定してしまう。',
        solution:
            '「AI 下書き・未確認」を独立した状態にし、AI が書き直すと確定は外れる。職員が書き換えると、どこからが人の判断なのかが履歴に残る。',
    },
    {
        challenge:
            '音声入力の文章は句読点がなく、口語で、専門用語が誤変換される。',
        solution:
            '原文はそのまま保存して後から差分を追えるようにし、記録用の文体への整形を AI とセットで成立させる。片方だけでは機能として成り立たない。',
    },
    {
        challenge:
            '入浴を終えた職員は、担当した方を順に入力したい。ご利用者ごとに画面を開き直していられない。',
        solution:
            '入浴・食事・バイタルの一括入力画面を用意し、同じ事実をご利用者からでも作業からでも入れられるようにする。',
    },
];

/* ------------------------------------------------------------------ *
 * 05 デモ
 * ------------------------------------------------------------------ */

const DEMO_ACCOUNTS: {
    icon: LucideIcon;
    role: string;
    email: string;
    note: string;
}[] = [
    {
        icon: ShieldCheck,
        role: '管理者',
        email: 'admin@example.com',
        note: 'AI 利用ログと費用まで含めたすべて',
    },
    {
        icon: ClipboardList,
        role: '生活相談員',
        email: 'manager@example.com',
        note: 'すべての記録の閲覧と編集',
    },
    {
        icon: HeartHandshake,
        role: '介護職員',
        email: 'staff@example.com',
        note: '自分が書いた記録のみ編集できる',
    },
];

const HIGHLIGHTS = [
    'ダッシュボードの「要対応のリスク」で、「数値から算出」と「AI が検出・要確認」のバッジが並ぶところ',
    '中村みつさんの詳細で「リスク兆候を抽出」を押す。体温 37.4℃ は発熱の基準（37.5℃）をあえて下回らせてあり、しきい値では検出されず、記録の「自覚症状はないが」を AI だけが拾う',
    '記録入力の音声入力欄に例文を入れて「変換」を押す。記録用・ご家族向け・申し送り用の 3 つが一度に生成される',
    '管理者で AI 利用ログを開く。成功だけでなく、失敗も種別ごとに集計している',
];

/* ------------------------------------------------------------------ *
 * 06 技術構成
 * ------------------------------------------------------------------ */

const SPECS = [
    { label: 'バックエンド', value: 'Laravel 13 / PHP 8.4' },
    {
        label: 'フロントエンド',
        value: 'Inertia.js + React 19 + TypeScript + Tailwind CSS',
    },
    { label: 'データベース', value: 'MariaDB（第 3 正規形・21 テーブル）' },
    { label: 'LLM', value: 'Anthropic Claude API（構造化出力）' },
    { label: 'AI 処理', value: 'キューで非同期実行、画面はポーリング' },
    { label: '静的解析', value: 'PHPStan level 7 / Laravel Pint' },
    { label: 'テスト', value: 'PHPUnit 340 件（CI は API キーなしで通る）' },
    { label: '配置', value: 'Laravel Cloud（Web とキューワーカー）' },
];

const DOCUMENTS = [
    {
        href: REQUIREMENTS_URL,
        title: '要件定義書',
        body: '背景・スコープ・LLM 連携の詳細設計・エラーハンドリング・データ保護',
    },
    {
        href: DATABASE_DESIGN_URL,
        title: 'データベース設計',
        body: '21 テーブルの ER 図、正規化の根拠、あえて正規化しない判断',
    },
    {
        href: SCALE_POLICY_URL,
        title: '200 拠点規模への対応方針',
        body: 'ピーク負荷の見積もりと、コード上のボトルネックの着手順序',
    },
    {
        href: DESIGN_GUIDE_URL,
        title: 'デザインガイド',
        body: '配色の構造（ブランド色と意味色の 2 軸）、共通部品、変えてはいけないもの',
    },
    {
        href: DEPLOY_GUIDE_URL,
        title: 'デプロイ手順',
        body: 'Laravel Cloud への配置、環境変数とその理由',
    },
];

export default function Welcome() {
    const { auth } = usePage<{ auth: Auth }>().props;
    const primaryHref = auth.user ? dashboard() : login();
    const primaryLabel = auth.user ? 'ダッシュボードへ' : 'デモにログイン';

    return (
        <>
            {/* アプリ名はタイトルの末尾に自動で付く（app.tsx）。ここでは重ねない */}
            <Head title="通所介護の記録とAI活用" />

            <div className="bg-background text-foreground min-h-screen">
                {/* --- 目次 --- */}
                <header className="bg-background/90 sticky top-0 z-30 border-b backdrop-blur">
                    <div className="mx-auto flex h-16 max-w-5xl items-center justify-between gap-4 px-6">
                        <a
                            href="#top"
                            className="flex items-center gap-2 font-bold tracking-tight"
                        >
                            <BrandMark className="size-8 rounded-lg" title="" />
                            CareLoop
                        </a>
                        <nav
                            aria-label="ページ内の目次"
                            className="hidden items-center gap-1 md:flex"
                        >
                            {NAV.map((item) => (
                                <a
                                    key={item.href}
                                    href={item.href}
                                    className="text-muted-foreground hover:text-foreground hover:bg-accent rounded-md px-3 py-2 text-sm font-medium"
                                >
                                    {item.label}
                                </a>
                            ))}
                        </nav>
                        <Button size="sm" asChild>
                            <Link href={primaryHref}>
                                {primaryLabel}
                                <ArrowRight className="size-4" aria-hidden />
                            </Link>
                        </Button>
                    </div>
                </header>

                <main id="top">
                    {/* --- 導入 --- */}
                    <section className="relative overflow-hidden">
                        <div
                            className="from-primary/10 pointer-events-none absolute inset-x-0 top-0 h-[480px] bg-gradient-to-b to-transparent"
                            aria-hidden
                        />
                        <div className="relative mx-auto grid max-w-5xl gap-12 px-6 pt-16 pb-20 lg:grid-cols-[1.1fr_1fr] lg:items-center lg:pt-24">
                            <div>
                                <p className="text-primary text-sm font-bold tracking-wide">
                                    通所介護（デイサービス）向け 記録・AI
                                    支援システム ／ 個人開発ポートフォリオ
                                </p>
                                <h1 className="mt-4 text-3xl leading-tight font-bold tracking-tight text-balance sm:text-4xl">
                                    話した一言が、記録にも、ご家族へのお便りにも、申し送りにもなる。
                                </h1>
                                <p className="text-muted-foreground mt-6 leading-relaxed">
                                    介護職員がスマートフォンに話しかけた一言から、AI
                                    が文体の異なる 3 つの文章を書き分けます。
                                    蓄積した記録からはリスクの兆候と目標の進捗を読み取り、職員が判断するための材料として提示します。
                                </p>
                                <p className="text-muted-foreground mt-3 leading-relaxed">
                                    Laravel から Claude API
                                    を呼び出し、プロンプト設計・構造化出力の検証・エラーハンドリング・費用管理までを、要件定義から一人で設計・実装しました。
                                </p>
                                <div className="mt-8 flex flex-wrap gap-3">
                                    <Button size="lg" asChild>
                                        <Link href={primaryHref}>
                                            {primaryLabel}
                                            <ArrowRight
                                                className="size-4"
                                                aria-hidden
                                            />
                                        </Link>
                                    </Button>
                                    <Button variant="outline" size="lg" asChild>
                                        <a
                                            href={REQUIREMENTS_URL}
                                            target="_blank"
                                            rel="noreferrer"
                                        >
                                            <BookOpen
                                                className="size-4"
                                                aria-hidden
                                            />
                                            要件定義書
                                        </a>
                                    </Button>
                                    <Button variant="outline" size="lg" asChild>
                                        <a
                                            href={REPOSITORY_URL}
                                            target="_blank"
                                            rel="noreferrer"
                                        >
                                            <FolderGit2
                                                className="size-4"
                                                aria-hidden
                                            />
                                            ソースコード
                                        </a>
                                    </Button>
                                </div>
                            </div>

                            <div className="relative lg:pr-12 lg:pb-10">
                                <Screenshot
                                    src="/images/intro/dashboard.webp"
                                    alt="ダッシュボード。本日のご利用者、未確定の記録、要対応のリスク、今月の AI 利用料を数字で示し、その下に重要度の高い指摘を検出元のバッジつきで並べている"
                                    priority
                                />
                                <div className="absolute right-0 bottom-0 hidden w-36 lg:block">
                                    <Screenshot
                                        src="/images/intro/record-edit-mobile.webp"
                                        alt="スマートフォンの記録入力画面。音声入力欄と、記録・ご家族向け・申し送りに変換するボタン"
                                        kind="mobile"
                                        priority
                                    />
                                </div>
                            </div>
                        </div>
                    </section>

                    {/* --- 01 背景と課題 --- */}
                    <IntroSection
                        id="background"
                        number="01"
                        label="背景と課題"
                        title="現場で 9 年、繰り返し見てきた 4 つの課題"
                        lead={
                            <>
                                <p>
                                    作者は介護施設運営企業（通所介護・福祉用具）に約
                                    9
                                    年在籍し、開設準備・事業推進・営業企画・IoT
                                    プラットフォーム部門を担当しました。送迎ルート設定システムや勤務シフト作成システムの導入では、現場とベンダーの橋渡し役として要件整理と社内推進を担いました。
                                </p>
                                <p>
                                    その過程で繰り返し見た、次の 4
                                    つの課題がこの作品の出発点です。
                                </p>
                            </>
                        }
                    >
                        <div className="grid gap-4 md:grid-cols-2">
                            {PROBLEMS.map((problem) => (
                                <IconCard
                                    key={problem.title}
                                    icon={problem.icon}
                                    eyebrow={problem.eyebrow}
                                    title={problem.title}
                                    tone="warning"
                                >
                                    {problem.body}
                                </IconCard>
                            ))}
                        </div>

                        <div className="border-primary/30 bg-primary/5 mt-8 rounded-xl border p-6">
                            <p className="text-primary text-sm font-bold tracking-wide">
                                課題の構造
                            </p>
                            <p className="mt-2 leading-relaxed">
                                4 つの課題は、どれも
                                <strong>
                                    「大量の自由記述から意味を取り出して、人に届ける」
                                </strong>
                                という同じ構造を持っています。入力と一覧だけの従来のシステムでは解決できず、LLM
                                の適用が本質的に有効な領域だと考えました。これが、LLM
                                連携を中核に据えた理由です。
                            </p>
                        </div>
                    </IntroSection>

                    {/* --- 02 解決の考え方 --- */}
                    <IntroSection
                        id="approach"
                        number="02"
                        label="解決の考え方"
                        title="入力の負荷は増やさず、記録の出口を増やす"
                        band
                        lead={
                            <p>
                                職員が話した一言は原文のまま保存され、AI
                                が「記録用」「ご家族向け」「申し送り用」の 3
                                つに書き分けます。同じ出来事でも読む相手によって適切な書き方は違い、職員が
                                3
                                回書くのは現実的ではありません。省略すれば記録は形骸化し、ご家族への報告は定型文になります。ここが
                                LLM の価値が最も明確に出る地点です。
                            </p>
                        }
                    >
                        <FlowFigure />

                        <div className="mt-10 grid gap-8 lg:grid-cols-[1fr_1.2fr] lg:items-start">
                            <div>
                                <h3 className="flex items-center gap-2 text-lg font-bold">
                                    <UserCheck
                                        className="text-primary size-5"
                                        aria-hidden
                                    />
                                    AI の出力は下書きとして扱う
                                </h3>
                                <p className="text-muted-foreground mt-2 text-sm leading-relaxed">
                                    介護記録はご利用者の健康と生命に関わり、法定の保存文書でもあります。LLM
                                    の出力は必ず「下書き」か「気づきの提示」として扱い、次の
                                    4 つを設計原則にしています。
                                </p>
                                <ol className="mt-4 space-y-2">
                                    {PRINCIPLES.map((principle, index) => (
                                        <li
                                            key={principle}
                                            className="flex gap-3 text-sm leading-relaxed"
                                        >
                                            <span className="bg-primary/10 text-primary flex size-6 shrink-0 items-center justify-center rounded-full font-mono text-xs font-bold">
                                                {index + 1}
                                            </span>
                                            {principle}
                                        </li>
                                    ))}
                                </ol>
                                <p className="text-muted-foreground mt-4 text-sm leading-relaxed">
                                    これは制約ではなく、業務システムに AI
                                    を組み込む際の要件そのものであり、この作品の設計上の主張です。
                                </p>
                            </div>

                            <Screenshot
                                src="/images/intro/resident.webp"
                                alt="ご利用者の詳細画面。お迎えの際にお伝えする事項と、リスク兆候の一覧。指摘ごとに重要度と検出元のバッジが付いている"
                                caption="ご利用者の詳細。「数値から算出」と「AI が検出・要確認」を別のバッジで示し、根拠の記録へ辿れる。"
                            />
                        </div>
                    </IntroSection>

                    {/* --- 03 AI をどう使ったか --- */}
                    <IntroSection
                        id="ai"
                        number="03"
                        label="AI の使い方"
                        title="使う場面を 3 つに絞り、それぞれに「任せないこと」を決める"
                        lead={
                            <p>
                                介護記録に AI
                                を使うと言ったとき、いちばん簡単なのは「記録を全部
                                LLM
                                に渡して、気づいたことを挙げてもらう」実装です。この作品はそれをしていません。数値で決まるものはルールで決め、読まないと分からない変化だけを
                                LLM に任せています。
                            </p>
                        }
                    >
                        <div className="grid gap-4 md:grid-cols-3">
                            {AI_USES.map((use) => (
                                <IconCard
                                    key={use.title}
                                    icon={use.icon}
                                    eyebrow={use.eyebrow}
                                    title={use.title}
                                    tone="ai"
                                >
                                    {use.body}
                                </IconCard>
                            ))}
                        </div>

                        <h3 className="mt-12 text-lg font-bold">
                            つまずいた課題と、その工夫
                        </h3>
                        <p className="text-muted-foreground mt-1 mb-6 text-sm">
                            設計段階で予想したものと、動かしてから分かったものの両方です。
                        </p>
                        <ChallengeSolutionList items={AI_CHALLENGES} />

                        <div className="mt-10">
                            <Screenshot
                                src="/images/intro/llm-logs.webp"
                                alt="AI 利用ログ画面。実行回数・成功率・今月の利用料・キャッシュ利用率の数字と、機能別の費用と所要時間の内訳"
                                caption="AI 利用ログ。機能別の費用と所要時間、失敗の種別を集計する。成功したものだけを見せても、エラーハンドリングを作った意味が伝わらない。"
                            />
                        </div>
                    </IntroSection>

                    {/* --- 04 UI --- */}
                    <IntroSection
                        id="ui"
                        number="04"
                        label="UI"
                        title="50〜60 代の職員が、片手で、現場で使える画面"
                        band
                        lead={
                            <p>
                                使うのは 50〜60 代が中心の介護職員で、端末は
                                iPhone、場面は片手がふさがった現場です。この前提から画面を決めています。
                            </p>
                        }
                    >
                        <div className="grid gap-4 sm:grid-cols-2">
                            {UI_FOCUS.map((item) => (
                                <IconCard
                                    key={item.title}
                                    icon={item.icon}
                                    title={item.title}
                                >
                                    {item.body}
                                </IconCard>
                            ))}
                        </div>

                        <div className="mt-12 grid gap-8 lg:grid-cols-[1.4fr_1fr] lg:items-start">
                            <div>
                                <h3 className="text-lg font-bold">
                                    現場の制約と、画面での解決
                                </h3>
                                <p className="text-muted-foreground mt-1 mb-6 text-sm">
                                    要件定義の
                                    F-18「スマートフォン向け記録画面」で決めた制約を、そのまま画面の仕様にしています。
                                </p>
                                <ChallengeSolutionList
                                    items={UI_CHALLENGES}
                                    solutionLabel="解決"
                                />
                            </div>

                            <div className="grid gap-8 sm:grid-cols-2 lg:grid-cols-1">
                                <Screenshot
                                    src="/images/intro/record-edit-mobile.webp"
                                    alt="スマートフォンの記録入力画面。音声入力欄、原文を書き換えないという説明、変換ボタン、画面下に固定された保存ボタン"
                                    kind="mobile"
                                    caption="記録入力。音声入力を起点に、AI が 3 つの文体へ書き分ける。保存は画面下に固定。"
                                />
                                <Screenshot
                                    src="/images/intro/dashboard-mobile.webp"
                                    alt="スマートフォンのダッシュボード。数値カードと要対応のリスク、画面下にホーム・記録・ご利用者・その他のタブバー"
                                    kind="mobile"
                                    caption="ダッシュボード。主要な操作は下部タブバーに集め、親指だけで届く範囲に置く。"
                                />
                            </div>
                        </div>
                    </IntroSection>

                    {/* --- 05 デモ --- */}
                    <IntroSection
                        id="demo"
                        number="05"
                        label="デモ"
                        title="役割を選んで、動いているものを見る"
                        lead={
                            <p>
                                架空のご利用者 20 名・約 3
                                ヶ月分の記録が入っています。権限によって見えるものが変わるため、役割ごとにアカウントを用意しました。押すとログイン画面へ移り、そこでも役割を選ぶだけで入れます。
                            </p>
                        }
                    >
                        <div className="grid gap-4 md:grid-cols-3">
                            {DEMO_ACCOUNTS.map((account) => (
                                <Link
                                    key={account.email}
                                    href={login()}
                                    className="group bg-card hover:border-primary hover:bg-accent/50 focus-visible:ring-ring shadow-card flex items-center gap-3 rounded-xl border p-5 transition-colors focus-visible:ring-2 focus-visible:outline-none"
                                >
                                    <span
                                        className="bg-primary/10 text-primary flex size-11 shrink-0 items-center justify-center rounded-lg"
                                        aria-hidden
                                    >
                                        <account.icon className="size-6" />
                                    </span>
                                    <span className="min-w-0 flex-1">
                                        <span className="block font-bold">
                                            {account.role}
                                        </span>
                                        <span className="text-muted-foreground block text-sm">
                                            {account.note}
                                        </span>
                                        <span className="text-muted-foreground mt-1 block font-mono text-xs">
                                            {account.email}
                                        </span>
                                    </span>
                                    <ArrowRight
                                        className="text-muted-foreground group-hover:text-primary size-5 shrink-0 transition-colors"
                                        aria-hidden
                                    />
                                </Link>
                            ))}
                        </div>
                        <p className="text-muted-foreground mt-3 text-sm">
                            パスワードはいずれも{' '}
                            <code className="bg-muted rounded px-1.5 py-0.5 font-mono">
                                password
                            </code>{' '}
                            です。表示されるご利用者・職員・記録はすべて架空のもので、実在の方とは関係ありません。実際の
                            API を呼ぶため、AI の実行には 1 分 3 回・1 日 40
                            回の流量制限をかけています。
                        </p>

                        <div className="mt-8 rounded-xl border p-6">
                            <h3 className="flex items-center gap-2 font-bold">
                                <Sparkles
                                    className="text-ai-ink size-5"
                                    aria-hidden
                                />
                                見どころ
                            </h3>
                            <ol className="mt-3 space-y-2">
                                {HIGHLIGHTS.map((highlight, index) => (
                                    <li
                                        key={highlight}
                                        className="flex gap-3 text-sm leading-relaxed"
                                    >
                                        <span className="bg-muted text-foreground flex size-6 shrink-0 items-center justify-center rounded-full font-mono text-xs font-bold">
                                            {index + 1}
                                        </span>
                                        {highlight}
                                    </li>
                                ))}
                            </ol>
                        </div>
                    </IntroSection>

                    {/* --- 06 技術構成 --- */}
                    <IntroSection
                        id="tech"
                        number="06"
                        label="技術"
                        title="技術構成とドキュメント"
                        band
                    >
                        <div className="grid gap-10 lg:grid-cols-[1fr_1.2fr]">
                            <div>
                                <h3 className="font-bold">技術構成</h3>
                                <dl className="mt-3 divide-y rounded-xl border">
                                    {SPECS.map((spec) => (
                                        <div
                                            key={spec.label}
                                            className="flex gap-3 px-4 py-2.5 text-sm"
                                        >
                                            <dt className="text-muted-foreground w-28 shrink-0">
                                                {spec.label}
                                            </dt>
                                            <dd>{spec.value}</dd>
                                        </div>
                                    ))}
                                </dl>
                                <p className="text-muted-foreground mt-3 text-sm leading-relaxed">
                                    スキーマの変遷はマイグレーションにそのまま残しています。完成形を
                                    1
                                    本で作り直してはいません。動いているものを壊さずに変える手順そのものを残す意図です。
                                </p>
                            </div>

                            <div>
                                <h3 className="font-bold">ドキュメント</h3>
                                <ul className="mt-3 grid gap-3">
                                    {DOCUMENTS.map((doc) => (
                                        <li key={doc.href}>
                                            <a
                                                href={doc.href}
                                                target="_blank"
                                                rel="noreferrer"
                                                className="group bg-card hover:border-primary focus-visible:ring-ring shadow-card flex items-start gap-3 rounded-xl border p-4 transition-colors focus-visible:ring-2 focus-visible:outline-none"
                                            >
                                                <ScrollText
                                                    className="text-primary mt-0.5 size-5 shrink-0"
                                                    aria-hidden
                                                />
                                                <span className="min-w-0">
                                                    <span className="group-hover:text-primary block font-bold">
                                                        {doc.title}
                                                    </span>
                                                    <span className="text-muted-foreground block text-sm">
                                                        {doc.body}
                                                    </span>
                                                </span>
                                            </a>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        </div>
                    </IntroSection>
                </main>

                <footer className="border-t">
                    <div className="text-muted-foreground mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-4 px-6 py-8 text-sm">
                        <p className="flex items-center gap-2">
                            <BrandMark className="size-6 rounded-md" title="" />
                            CareLoop ―
                            個人開発のポートフォリオ。表示されているご利用者・職員・記録はすべて架空のものです。
                        </p>
                        <a
                            href={REPOSITORY_URL}
                            target="_blank"
                            rel="noreferrer"
                            className="hover:text-foreground inline-flex items-center gap-1.5"
                        >
                            <FolderGit2 className="size-4" aria-hidden />
                            GitHub
                        </a>
                    </div>
                </footer>
            </div>
        </>
    );
}
