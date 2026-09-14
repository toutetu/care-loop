import { Link } from '@inertiajs/react';
import { ClipboardList, LayoutGrid, Menu, Users } from 'lucide-react';
import { useSidebar } from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import records from '@/routes/records';
import residents from '@/routes/residents';

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
 * 【なぜ4つか】
 * 毎日使う3つだけを出し、残りは「その他」からドロワーで開く。
 * 職員アカウント・編集履歴・AI利用ログは管理者が事務所で見るもので、
 * 送迎や入浴の合間に押すものではない。
 *
 * 【寸法】
 * iPhone SE（375×667）を基準にしている。1つあたりの高さは56pxで、
 * 最小タッチ目標44pxに余裕を持たせてある。横は375/4＝93pxずつ。
 * 下端はホームバーに隠れないよう safe-area を足す。
 */

const TABS = [
    { title: 'ホーム', href: dashboard(), icon: LayoutGrid },
    { title: '記録', href: records.index(), icon: ClipboardList },
    { title: 'ご利用者', href: residents.index(), icon: Users },
];

/** タブ1つぶんの見た目。リンクでも「その他」ボタンでも同じ形にする。 */
const TAB_SHAPE =
    'flex min-h-14 flex-1 flex-col items-center justify-center gap-1 rounded-md text-xs font-medium outline-none focus-visible:ring-ring focus-visible:ring-[3px]';

export function MobileTabBar() {
    const { isCurrentUrl } = useCurrentUrl();
    const { setOpenMobile } = useSidebar();

    return (
        <nav
            aria-label="主なメニュー"
            className="bg-background/95 fixed inset-x-0 bottom-0 z-40 border-t backdrop-blur md:hidden"
            style={{ paddingBottom: 'env(safe-area-inset-bottom)' }}
        >
            {/* gap-2 は、モックの「隣接要素との間隔を8px以上」に合わせている。
                手袋をした指では、隣を押してしまう事故のほうが多い。 */}
            <div className="flex items-stretch gap-2 px-2 py-1">
                {TABS.map((tab) => {
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
