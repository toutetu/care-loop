import { Link } from '@inertiajs/react';
import { BrandMark } from '@/components/brand-mark';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({
    breadcrumbs = [],
    mobileTabBar = true,
}: {
    breadcrumbs?: BreadcrumbItemType[];
    mobileTabBar?: boolean;
}) {
    return (
        <header className="border-sidebar-border/50 flex h-16 shrink-0 items-center gap-2 border-b px-4 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4">
            <div className="flex min-w-0 items-center gap-2">
                {/*
                 * 下部タブバーがある画面では、この開閉ボタンをスマートフォンで隠す。
                 * 左上の角は親指がいちばん届かない場所で、同じドロワーへの入口が
                 * 上下に2つあると、押しやすいほう（下）が使われなくなる。
                 */}
                <SidebarTrigger
                    className={mobileTabBar ? 'max-md:hidden' : ''}
                />

                {/* スマートフォンではサイドバーが隠れ、ロゴがどこにも出ない。
                    マークだけをここに置く。文字を添えるとパンくずと競合する。 */}
                <Link
                    href={dashboard()}
                    prefetch
                    aria-label="ダッシュボードへ"
                    className="focus-visible:ring-ring shrink-0 rounded-md outline-none focus-visible:ring-2 md:hidden"
                >
                    <BrandMark className="size-7 rounded-md" title="" />
                </Link>

                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>
        </header>
    );
}
