import type { ReactNode } from 'react';
import type { BreadcrumbItem } from '@/types/navigation';

export type AppLayoutProps = {
    children: ReactNode;
    breadcrumbs?: BreadcrumbItem[];
    /**
     * スマートフォンで下部タブバーを出すか。既定は出す。
     * 記録の入力のように、1画面で終わらせる作業では false にして
     * 画面を広く使い、押せるものを減らす。
     */
    mobileTabBar?: boolean;
};

export type AppVariant = 'header' | 'sidebar';

export type FlashToast = {
    type: 'success' | 'info' | 'warning' | 'error';
    message: string;
};

export type AuthLayoutProps = {
    children?: ReactNode;
    name?: string;
    title?: string;
    description?: string;
};
