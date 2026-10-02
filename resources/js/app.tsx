import { createInertiaApp } from '@inertiajs/react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import { registerRequestFailureToast } from '@/lib/request-failure-toast';
import AppLayout from '@/layouts/app-layout';
import { initializeDeviceClass } from '@/lib/device-class';
import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'welcome':
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    withApp(app) {
        return (
            <TooltipProvider delayDuration={0}>
                {app}
                <Toaster />
            </TooltipProvider>
        );
    },
    progress: {
        color: '#4B5563',
    },
});

// サーバーから画面を返せなかったときに、英語のエラーページを
// そのまま見せず、日本語で状況を出す。
registerRequestFailureToast();

// This will set light / dark mode on load...
initializeTheme();

// ログイン直後に開く画面を、端末に合わせて選べるようにする（StartController）
initializeDeviceClass();
