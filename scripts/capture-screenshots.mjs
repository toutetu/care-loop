/**
 * 紹介ページ（resources/js/pages/welcome.tsx）に載せる画面写真を撮る。
 *
 * 使い方:
 *   php artisan serve            # 別のターミナルで（デモデータ投入済みのこと）
 *   node scripts/capture-screenshots.mjs
 *   node scripts/capture-screenshots.mjs --base http://localhost:8000
 *
 * 出力先は public/images/intro/。ファイル名は紹介ページの <img src> と
 * 対応しているので、名前を変えるときは welcome.tsx も直す。
 *
 * 【撮り直しが要るとき】
 * 画面の配色や部品を変えたら撮り直す。写真は「動いているものを見せる」
 * ためにあり、古い見た目の写真が残っていると、実物を開いた人が別物だと
 * 感じる。撮り直したら差分を目で見て、架空のご利用者の名前しか写って
 * いないことを確かめる。
 *
 * 【ログイン】
 * デモ用の管理者アカウント（admin@example.com / password）で入る。
 * 管理者にしているのは、AI利用ログまで撮るため。
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { launchBrowser } from './lib/browser.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const outDir = path.join(root, 'public/images/intro');

const args = process.argv.slice(2);
const baseIndex = args.indexOf('--base');
const base = baseIndex >= 0 ? args[baseIndex + 1] : 'http://localhost:8000';

const DESKTOP = { width: 1280, height: 800, deviceScaleFactor: 2 };
const MOBILE = {
    width: 390,
    height: 844,
    deviceScaleFactor: 2,
    isMobile: true,
    hasTouch: true,
};

fs.mkdirSync(outDir, { recursive: true });

const browser = await launchBrowser();
const page = await browser.newPage();

/** Vite のフォント読み込みとポーリングを待つため、networkidle まで待つ。 */
async function open(url, viewport) {
    await page.setViewport(viewport);
    await page.goto(`${base}${url}`, { waitUntil: 'networkidle2' });
    // フォントの適用を待つ。読み込み直後に撮るとフォールバック書体で写る。
    await page.evaluate(() => document.fonts.ready);
}

async function shoot(name, { fullPage = false } = {}) {
    const file = path.join(outDir, `${name}.webp`);
    await page.screenshot({ path: file, type: 'webp', quality: 88, fullPage });
    console.log(`  ${path.relative(root, file)}`);
}

// --- ログイン ---
// Inertia のフォームは XHR で送り、応答を受けてから画面を差し替える。
// waitForNavigation は差し替えの途中で解決してしまい、その直後に別の
// URL へ移ると送信中の POST が打ち切られてログインできない。
// POST の応答と URL の変化の両方を待つ。
await open('/login', DESKTOP);
await page.type('#email', 'admin@example.com');
await page.type('#password', 'password');
const loginResponse = page.waitForResponse(
    (response) =>
        response.url().endsWith('/login') &&
        response.request().method() === 'POST',
);
await page.click('[data-test="login-button"]');
const status = (await loginResponse).status();
await page.waitForFunction(() => location.pathname === '/dashboard', {
    timeout: 15000,
});
if (status >= 400) {
    throw new Error(`ログインに失敗しました（HTTP ${status}）`);
}

console.log('撮影中:');

// --- ダッシュボード（PC） ---
await open('/dashboard', DESKTOP);
await shoot('dashboard');

// --- ご利用者の詳細（PC）。AIとルールの検出元バッジが並ぶ画面。 ---
// デモデータの中村みつさんは、体温がしきい値未満で記述からのみ検出される
// 仕込みがあるので、この方を優先する。いなければ先頭の方。
await open('/residents', DESKTOP);
const residentHref = await page.evaluate(() => {
    const links = [...document.querySelectorAll('a[href*="/residents/"]')];
    const preferred = links.find((a) => a.textContent?.includes('中村'));
    return (preferred ?? links[0])?.getAttribute('href') ?? null;
});
if (residentHref) {
    await page.goto(new URL(residentHref, base).href, {
        waitUntil: 'networkidle2',
    });
    await page.evaluate(() => document.fonts.ready);
    await shoot('resident');
}

// --- 記録一覧（PC） ---
await open('/records', DESKTOP);
await shoot('records');

// --- AI利用ログ（PC、管理者のみ） ---
await open('/llm-logs', DESKTOP);
await shoot('llm-logs');

// --- 記録入力（スマートフォン）。音声入力とAI三面変換の画面。 ---
await open('/records', MOBILE);
const recordHref = await page.evaluate(
    () =>
        document
            .querySelector('a[href*="/records/"][href$="/edit"]')
            ?.getAttribute('href') ?? null,
);
if (recordHref) {
    await page.goto(new URL(recordHref, base).href, {
        waitUntil: 'networkidle2',
    });
    await page.evaluate(() => document.fonts.ready);
    await shoot('record-edit-mobile');
}

// --- ダッシュボード（スマートフォン）。下部タブバーを見せる。 ---
await open('/dashboard', MOBILE);
await shoot('dashboard-mobile');

// --- ログイン画面（スマートフォン） ---
await page.evaluate(async () => {
    const token = decodeURIComponent(
        document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
    );
    await fetch('/logout', {
        method: 'POST',
        headers: { 'X-XSRF-TOKEN': token, Accept: 'application/json' },
        credentials: 'same-origin',
    });
});
await open('/login', MOBILE);
await shoot('login-mobile');

await browser.close();
console.log('完了。public/images/intro/ を確認してください。');
