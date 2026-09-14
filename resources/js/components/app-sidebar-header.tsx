import { Breadcrumbs } from '@/components/breadcrumbs';
import { SidebarTrigger } from '@/components/ui/sidebar';
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
            <div className="flex items-center gap-2">
                {/*
                 * 下部タブバーがある画面では、この開閉ボタンをスマートフォンで隠す。
                 * 左上の角は親指がいちばん届かない場所で、同じドロワーへの入口が
                 * 上下に2つあると、押しやすいほう（下）が使われなくなる。
                 */}
                <SidebarTrigger
                    className={mobileTabBar ? 'max-md:hidden' : ''}
                />
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>
        </header>
    );
}
