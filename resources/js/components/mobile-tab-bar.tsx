import { Link, usePage } from '@inertiajs/react';
import { Bell, ClipboardList, Menu, MessagesSquare } from 'lucide-react';
import { useSidebar } from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { COUNT_BADGE } from '@/lib/care-presentation';
import { cn } from '@/lib/utils';
import messages from '@/routes/messages';
import notices from '@/routes/notices';
import records from '@/routes/records';

/**
 * スマートフォンの下部タブバー。
 *
 * 【なぜ下に置くか】
 * 現場では片手しか空いていない。もう片方の手でご利用者を支えている。
 * 立ったまま親指だけで操作するとき、画面の上端――とくに左上の角は
 * いちばん届かない場所である。ナビゲーションを上に置くハンバーガーは
 * この制約に逆らう。docs/mock/mobile.html の「主要操作をすべて画面
 * 下部3分の1に配置。上部は情報表示のみ」という原則に合わせてある。
 *
 * 【なぜこの4つか】
 * スマートフォンを開くのは、介助の合間に気づいたことを残すときと、
 * 気をつけることや職員からの連絡を確かめるときである。ダッシュボードと
 * 利用者一覧は朝礼や事務所で見るもので、「その他」からドロワーで開く。
 *
 * 【寸法】
 * iPhone SE（375×667）を基準にしている。1つあたりの高さは56pxで、
 * 最小タッチ目標44pxに余裕を持たせてある。横は375/4＝93pxずつ。
 * 下端はホームバーに隠れないよう safe-area を足す。
 */

/** タブ1つぶんの見た目。リンクでも「その他」ボタンでも同じ形にする。 */
const TAB_SHAPE =
    'relative flex min-h-14 flex-1 flex-col items-center justify-center gap-1 rounded-md text-xs font-medium outline-none focus-visible:ring-ring focus-visible:ring-[3px]';

export function MobileTabBar() {
    const { isCurrentUrl } = useCurrentUrl();
    const { setOpenMobile } = useSidebar();
    const { noticeCount, messageUnread } = usePage().props;

    const tabs = [
        { title: '記録', href: records.index(), icon: ClipboardList, count: 0 },
        {
            title: 'お知らせ',
            href: notices.index(),
            icon: Bell,
            count: noticeCount,
        },
        {
            title: '連絡',
            href: messages.index(),
            icon: MessagesSquare,
            count: messageUnread,
        },
    ];

    return (
        <nav
            aria-label="主なメニュー"
            className="bg-background/95 fixed inset-x-0 bottom-0 z-40 border-t backdrop-blur xl:hidden"
            style={{ paddingBottom: 'env(safe-area-inset-bottom)' }}
        >
            {/* gap-2 は、モックの「隣接要素との間隔を8px以上」に合わせている。
                手袋をした指では、隣を押してしまう事故のほうが多い。 */}
            <div className="flex items-stretch gap-2 px-2 py-1">
                {tabs.map((tab) => {
                    const active = isCurrentUrl(tab.href);

                    return (
                        <Link
                            key={tab.title}
                            href={tab.href}
                            prefetch
                            aria-current={active ? 'page' : undefined}
                            className={cn(
                                TAB_SHAPE,
                                active
                                    ? 'text-foreground'
                                    : 'text-muted-foreground',
                            )}
                        >
                            <tab.icon
                                className={cn(
                                    'size-6',
                                    active && 'stroke-[2.5]',
                                )}
                                aria-hidden
                            />
                            {tab.title}
                            {/* 件数が無ければ開かれない。読み上げでは件数を名前に足す */}
                            {tab.count > 0 && (
                                <>
                                    <span
                                        className={cn(
                                            'absolute top-1 left-1/2 ml-2 min-w-5 rounded-full px-1.5 text-center text-[11px] leading-5 font-bold tabular-nums',
                                            COUNT_BADGE,
                                        )}
                                        aria-hidden
                                    >
                                        {tab.count > 99 ? '99+' : tab.count}
                                    </span>
                                    <span className="sr-only">
                                        （{tab.count}件）
                                    </span>
                                </>
                            )}
                        </Link>
                    );
                })}

                {/* 管理者向けの画面と設定は、ここからドロワーで開く。
                    サイドバーの中身をそのまま使うので、出口が二重にならない。 */}
                <button
                    type="button"
                    onClick={() => setOpenMobile(true)}
                    className={cn(TAB_SHAPE, 'text-muted-foreground')}
                >
                    <Menu className="size-6" aria-hidden />
                    その他
                </button>
            </div>
        </nav>
    );
}
