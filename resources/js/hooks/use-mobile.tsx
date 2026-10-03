import { useSyncExternalStore } from 'react';

/*
 * 下のバーとドロワーで操作する画面か。
 *
 * スマートフォン（〜767px）に加え、指で操作するタブレット（〜1279px）も含める。
 * タブレットはフロアに置いて立ったまま使うので、常設のサイドバーより、
 * 親指の届く下のバーのほうが押しやすい。
 *
 * マウスで使う PC は、ウィンドウを半分にして 1280px を下回っても含めない。
 * 他の作業と並べて記録を読むときに、画面の作りが変わらないようにする。
 *
 * サイドバー（ui/sidebar.tsx）、通知の位置（ui/sonner.tsx）、下のバー
 * （mobile-tab-bar.tsx の md:pointer-fine:hidden xl:hidden）を同じ条件で
 * 切り替える。device-class.ts の判定とも同じ。
 */
const TAB_BAR_QUERY =
    '(max-width: 767px), (max-width: 1279px) and (pointer: coarse)';

const mql =
    typeof window === 'undefined'
        ? undefined
        : window.matchMedia(TAB_BAR_QUERY);

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
