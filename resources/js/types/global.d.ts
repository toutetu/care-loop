import type { Auth } from '@/types/auth';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            sidebarOpen: boolean;
            /** 下のバーの「お知らせ」に出す件数（HandleInertiaRequests） */
            noticeCount: number;
            /** 下のバーの「連絡」に出す未読の数（HandleInertiaRequests） */
            messageUnread: number;
            [key: string]: unknown;
        };
    }
}
