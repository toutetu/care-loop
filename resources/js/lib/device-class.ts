/**
 * 端末の種類を画面幅で決め、Cookie に書いてサーバーへ伝える。
 *
 * 【幅で決める】
 * ブラウザが名乗る端末名（User-Agent）は使わない。iPad は Mac と名乗るので
 * 見分けられず、同じ端末でも分割表示にすれば幅が変わる。いま見えている幅に
 * 合わせるほうが、職員が目にする画面と食い違わない。
 *
 * 境目は Tailwind の md（768px）と xl（1280px）に揃える。画面の組み替えと
 * ログイン直後の行き先（StartController）が、同じ線で切り替わるようにする。
 *
 * 【タブレットは指で操作するものに限る】
 * PC でも、他の作業と並べてウィンドウを半分にすると 1280px を下回る。
 * 幅だけで決めると、マウスで使っている PC が下のバーの画面になってしまう。
 * 768〜1279px でも、主な操作がマウス（pointer: fine）なら PC として扱う。
 */

export type DeviceClass = 'phone' | 'tablet' | 'desktop';

const TABLET_MIN = 768;
const DESKTOP_MIN = 1280;

export function currentDeviceClass(): DeviceClass {
    const width = window.innerWidth;

    if (width < TABLET_MIN) {
        return 'phone';
    }

    const touch = window.matchMedia('(pointer: coarse)').matches;

    return width < DESKTOP_MIN && touch ? 'tablet' : 'desktop';
}

function writeCookie(value: DeviceClass): void {
    const maxAge = 365 * 24 * 60 * 60;
    document.cookie = `device=${value};path=/;max-age=${maxAge};SameSite=Lax`;
}

/**
 * 読み込み時と、幅が境目をまたいだときに書き直す。
 *
 * ログイン画面でも動かしておく。ログインの送信より前に Cookie が
 * 入っていないと、最初のログインでスマートフォンなのにダッシュボードが開く。
 */
export function initializeDeviceClass(): void {
    if (typeof window === 'undefined') {
        return;
    }

    writeCookie(currentDeviceClass());

    for (const width of [TABLET_MIN, DESKTOP_MIN]) {
        window
            .matchMedia(`(min-width: ${width}px)`)
            .addEventListener('change', () =>
                writeCookie(currentDeviceClass()),
            );
    }
}
