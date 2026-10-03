import { useSyncExternalStore } from 'react';

/*
 * 下のバーとドロワーで操作する幅の上限（Tailwind の xl）。
 *
 * スマートフォンだけでなくタブレットも含める。タブレットはフロアに置いて
 * 立ったまま指で使うので、PC と同じ常設のサイドバーより、親指の届く下のバーの
 * ほうが押しやすい。サイドバー（ui/sidebar.tsx）、通知の位置（ui/sonner.tsx）、
 * 下のバー（mobile-tab-bar.tsx の xl:hidden）を、同じ境目で切り替える。
 * device-class.ts の DESKTOP_MIN と同じ値。
 */
const MOBILE_BREAKPOINT = 1280;

const mql =
    typeof window === 'undefined'
        ? undefined
        : window.matchMedia(`(max-width: ${MOBILE_BREAKPOINT - 1}px)`);

function mediaQueryListener(callback: (event: MediaQueryListEvent) => void) {
    if (!mql) {
        return () => {};
    }

    mql.addEventListener('change', callback);

    return () => {
        mql.removeEventListener('change', callback);
    };
}

function isSmallerThanBreakpoint(): boolean {
    return mql?.matches ?? false;
}

function getServerSnapshot(): boolean {
    return false;
}

export function useIsMobile(): boolean {
    return useSyncExternalStore(
        mediaQueryListener,
        isSmallerThanBreakpoint,
        getServerSnapshot,
    );
}
