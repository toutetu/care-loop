import { Link, usePage } from '@inertiajs/react';
import {
    Activity,
    Bath,
    Bell,
    ClipboardList,
    HeartPulse,
    History,
    LayoutGrid,
    Megaphone,
    MessagesSquare,
    Sparkles,
    UserCog,
    Users,
    UtensilsCrossed,
} from 'lucide-react';
import BatchEntryController from '@/actions/App/Http/Controllers/BatchEntryController';
import AppLogo from '@/components/app-logo';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
    SidebarSeparator,
} from '@/components/ui/sidebar';
import { toUrl } from '@/lib/utils';
import announcements from '@/routes/announcements';
import auditLogs from '@/routes/audit-logs';
import { dashboard } from '@/routes';
import llmJobs from '@/routes/llm-jobs';
import llmLogs from '@/routes/llm-logs';
import messages from '@/routes/messages';
import notices from '@/routes/notices';
import records from '@/routes/records';
import residents from '@/routes/residents';
import staff from '@/routes/staff';
import type { NavItem } from '@/types';

/**
 * サイドバー。
 *
 * 【なぜ区切って見出しを付けるか】
 * 平らに9個並べると、毎日使う「記録を書く」と、月に一度開く「AI利用ログ」が
 * 同じ重さで見える。職員は50〜60代が中心で、目で端から端まで走査させる
 * 作りは負担になる。やることの種類でまとめ、探す範囲を先に狭める。
 *
 * 【見出しと項目を段で分ける】
 * 見出しを薄くするだけでは、項目と同じ列に並ぶかぎり「押せるもの」に見える。
 * 項目を1段右へ下げて、見出しにぶら下がっている形を作る。色の濃淡が
 * 読み取りにくくても、位置だけで親子が分かる。
 *
 * 【編集履歴を親の下に置く】
 * 編集履歴は単独で開くものではなく「この一覧の変更を辿りたい」ときに開く。
 * 利用者一覧・職員一覧のすぐ下に置き、対象で絞り込んだ状態へ直接入る。
 */

/** 子を持てるナビ項目。子は親の直下にぶら下げる。 */
type SidebarEntry = NavItem & {
    children?: NavItem[];
};

type SidebarSection = {
    /** 見出し。先頭の区画（ダッシュボードとお知らせ）には付けない。 */
    label?: string;
    items: SidebarEntry[];
};

type SidebarPageProps = {
    auth: { user: { role?: string } | null };
};

export function AppSidebar() {
    const page = usePage<SidebarPageProps>();
    const role = page.props.auth.user?.role;
    const isAdmin = role === 'admin';

    /*
     * AI運用は介護職員に出さない。一覧には事業所ぜんぶの実行が並ぶため、
     * 運用を預かる側の画面である（UserRole::canViewLlmJobs と揃える）。
     * ここで隠すのは見た目の話で、開かせない担保はコントローラ側にある。
     */
    const canViewLlmOps = role !== undefined && role !== 'staff';

    /*
     * 現在地の判定。
     *
     * 利用者編集履歴と職員編集履歴は同じ /audit-logs を指し、type だけが違う。
     * パスだけで比べると両方が選択中になるため、クエリまで含めて判定する。
     */
    const isCurrent = (href: NavItem['href']): boolean => {
        const target = toUrl(href);
        const [targetPath, targetQuery] = target.split('?');
        const [currentPath, currentQuery] = page.url.split('?');

        if (targetPath !== currentPath) {
            return false;
        }

        const typeOf = (query?: string) =>
            new URLSearchParams(query ?? '').get('type') ?? '';

        return typeOf(targetQuery) === typeOf(currentQuery);
    };

    const sections: SidebarSection[] = [
        {
            items: [
                {
                    title: 'ダッシュボード',
                    href: dashboard(),
                    icon: LayoutGrid,
                },
                // スマートフォンでは下のバーにある。PCとタブレットでも
                // 同じ場所へ行けるよう、ここにも並べる。
                { title: 'お知らせ', href: notices.index(), icon: Bell },
                { title: '連絡', href: messages.index(), icon: MessagesSquare },
                {
                    title: '周知',
                    href: announcements.index(),
                    icon: Megaphone,
                },
            ],
        },
        {
            // 記録一覧はご利用者ごと、下の3つは作業ごとの入り口。
            // 入浴介助を終えた職員は、担当した方を順に入れたい。
            // 同じ事実をどちらからでも入れられる。
            label: '入力',
            items: [
                {
                    title: '記録一覧',
                    href: records.index(),
                    icon: ClipboardList,
                },
                {
                    title: '入浴入力',
                    href: BatchEntryController.index.url({ kind: 'bathing' }),
                    icon: Bath,
                },
                {
                    title: '食事入力',
                    href: BatchEntryController.index.url({ kind: 'meal' }),
                    icon: UtensilsCrossed,
                },
                {
                    title: 'バイタル入力',
                    href: BatchEntryController.index.url({ kind: 'vital' }),
                    icon: HeartPulse,
                },
            ],
        },
        {
            // ご利用者と職員の登録内容そのもの。介護の現場で「台帳」は
            // この種の register を指す言葉として通っている。
            label: '台帳',
            items: [
                {
                    title: '利用者一覧',
                    href: residents.index(),
                    icon: Users,
                    children: isAdmin
                        ? [
                              {
                                  title: '利用者編集履歴',
                                  href: auditLogs.index({
                                      query: { type: 'residents' },
                                  }),
                                  icon: History,
                              },
                          ]
                        : undefined,
                },
                ...(isAdmin
                    ? [
                          {
                              title: '職員一覧',
                              href: staff.index(),
                              icon: UserCog,
                              children: [
                                  {
                                      title: '職員編集履歴',
                                      href: auditLogs.index({
                                          query: { type: 'staff' },
                                      }),
                                      icon: History,
                                  },
                              ],
                          },
                      ]
                    : []),
            ],
        },
        /*
         * 押した処理が通ったかの確認（実行状況）と、費用・失敗率の集計
         * （利用ログ）。どちらもAIを動かした結果を見にくる場所であり、
         * 介護職員には区画ごと出さない。
         */
        ...(canViewLlmOps
            ? [
                  {
                      label: 'AI運用',
                      items: [
                          {
                              title: 'AI実行状況',
                              href: llmJobs.index(),
                              icon: Activity,
                          },
                          ...(isAdmin
                              ? [
                                    {
                                        title: 'AI利用ログ',
                                        href: llmLogs.index(),
                                        icon: Sparkles,
                                    },
                                ]
                              : []),
                      ],
                  },
              ]
            : []),
    ];

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                {sections.map((section, index) => (
                    <div key={section.label ?? 'main'}>
                        {index > 0 && <SidebarSeparator className="mx-0" />}

                        {/* 項目を1段下げるぶん、区画そのものの取り代は詰める。
                            幅16remは動かせないので、増やした段の幅は
                            どこかから借りるしかない */}
                        <SidebarGroup className="px-1 py-0">
                            {section.label && (
                                /*
                                 * 見出しは押すものではない。項目より薄くして、
                                 * 目が先に項目へ向くようにする。
                                 *
                                 * 60%より下げない。明モードでちょうど 4.5:1
                                 * （WCAG AA）で、55%にすると 3.83:1 まで落ちる。
                                 * 本文の 7:1 には届かないが、見出しは読む順を
                                 * 示すためのもので、読み取れなくなってはいけない。
                                 */
                                <SidebarGroupLabel className="text-sidebar-foreground/60">
                                    {section.label}
                                </SidebarGroupLabel>
                            )}

                            {/* 折りたたむと見出しは消え、幅も3remしか残らない。
                                そのときは下げず、アイコンを中央に置いたままにする */}
                            <SidebarMenu
                                className={
                                    section.label
                                        ? 'pl-4 group-data-[collapsible=icon]:pl-0'
                                        : undefined
                                }
                            >
                                {section.items.map((item) => (
                                    <SidebarMenuItem key={item.title}>
                                        <SidebarMenuButton
                                            asChild
                                            isActive={isCurrent(item.href)}
                                            tooltip={{ children: item.title }}
                                        >
                                            {navLink(item)}
                                        </SidebarMenuButton>

                                        {item.children && (
                                            /* 同じ理由で子の取り代も詰める。
                                               既定のままだと「利用者編集履歴」が
                                               入りきらず途中で切れる */
                                            <SidebarMenuSub className="mx-1.5 px-2">
                                                {item.children.map((child) => (
                                                    <SidebarMenuSubItem
                                                        key={child.title}
                                                    >
                                                        <SidebarMenuSubButton
                                                            asChild
                                                            isActive={isCurrent(
                                                                child.href,
                                                            )}
                                                        >
                                                            {navLink(child)}
                                                        </SidebarMenuSubButton>
                                                    </SidebarMenuSubItem>
                                                ))}
                                            </SidebarMenuSub>
                                        )}
                                    </SidebarMenuItem>
                                ))}
                            </SidebarMenu>
                        </SidebarGroup>
                    </div>
                ))}
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}

/**
 * 1件ぶんのリンクを組み立てる。
 *
 * コンポーネントではなく関数にしてある。SidebarMenuButton の asChild は
 * Radix の Slot で className を子へ渡すが、間にコンポーネントを挟むと
 * 受け取って捨ててしまい、並びも余白も効かずアイコンと文字が縦に積まれる。
 * 要素を直接返せば、Slot の子が <a> / <Link> そのものになる。
 */
function navLink(item: NavItem) {
    return (
        <Link href={item.href} prefetch>
            {item.icon && <item.icon />}
            <span>{item.title}</span>
        </Link>
    );
}
