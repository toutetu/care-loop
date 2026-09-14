import { useFlashToasts } from '@/hooks/use-flash-toasts';
import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
import type { AppLayoutProps } from '@/types';

export default function AppLayout({
    breadcrumbs = [],
    mobileTabBar = true,
    children,
}: AppLayoutProps) {
    useFlashToasts();

    return (
        <AppLayoutTemplate
            breadcrumbs={breadcrumbs}
            mobileTabBar={mobileTabBar}
        >
            {children}
        </AppLayoutTemplate>
    );
}
