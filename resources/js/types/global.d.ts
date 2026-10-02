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
            [key: string]: unknown;
        };
    }
}
