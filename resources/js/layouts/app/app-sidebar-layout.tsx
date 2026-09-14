import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { MobileTabBar } from '@/components/mobile-tab-bar';
import { cn } from '@/lib/utils';
import type { AppLayoutProps } from '@/types';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
    mobileTabBar = true,
}: AppLayoutProps) {
    return (
        <AppShell variant="sidebar">
            <AppSidebar />
            <AppContent
                variant="sidebar"
                className={cn(
                    'min-w-0 overflow-x-clip',
                    // 下部タブバーのぶんだけ内容を空ける。これが無いと、
                    // いちばん下の保存ボタンがタブバーに隠れる。
                    mobileTabBar && 'pb-20 md:pb-0',
                )}
            >
                <AppSidebarHeader
                    breadcrumbs={breadcrumbs}
                    mobileTabBar={mobileTabBar}
                />
                {children}
            </AppContent>
            {mobileTabBar && <MobileTabBar />}
        </AppShell>
    );
}
