/**
 * public/favicon.svg から favicon.ico と apple-touch-icon.png を作る。
 *
 * 使い方:
 *   node scripts/build-icons.mjs
 *
 * ロゴの正本は resources/js/components/brand-mark.tsx と public/favicon.svg の
 * 2つ（同じ絵を JSX と SVG で持っている）。色や形を変えたら両方を直し、
 * このスクリプトでビットマップを作り直す。
 *
 * apple-touch-icon は角を丸めずに塗り潰す。iOS が自分の角丸で切り抜くため、
 * こちらで透明にしておくと隅が黒く残る。favicon.ico は透明な角のまま。
 *
 * ブラウザの探し方は scripts/lib/browser.mjs を参照。
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { launchBrowser } from './lib/browser.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const svg = fs.readFileSync(path.join(root, 'public/favicon.svg'), 'utf8');

const browser = await launchBrowser();
const page = await browser.newPage();

async function render(size, { square = false } = {}) {
    const markup = svg
        .replace(
            '<svg ',
            `<svg width="${size}" height="${size}" style="display:block" `,
        )
        .replace(/rx="116"/g, square ? 'rx="0"' : 'rx="116"');

    await page.setViewport({ width: size, height: size, deviceScaleFactor: 1 });
    await page.setContent(
        `<!doctype html><html><body style="margin:0;background:transparent">${markup}</body></html>`,
    );

    return page.screenshot({
        type: 'png',
        omitBackground: true,
        clip: { x: 0, y: 0, width: size, height: size },
    });
}

fs.writeFileSync(
    path.join(root, 'public/apple-touch-icon.png'),
    await render(180, { square: true }),
);

// ICO は PNG をそのまま格納できる（Vista 以降・全モダンブラウザ対応）。
const entries = [];
for (const size of [16, 32, 48]) {
    entries.push({ size, png: await render(size) });
}

const header = Buffer.alloc(6);
header.writeUInt16LE(0, 0); // reserved
header.writeUInt16LE(1, 2); // type: icon
header.writeUInt16LE(entries.length, 4);

let offset = 6 + 16 * entries.length;
const directory = [];
for (const { size, png } of entries) {
    const entry = Buffer.alloc(16);
    entry.writeUInt8(size, 0);
    entry.writeUInt8(size, 1);
    entry.writeUInt8(0, 2); // palette
    entry.writeUInt8(0, 3); // reserved
    entry.writeUInt16LE(1, 4); // planes
    entry.writeUInt16LE(32, 6); // bpp
    entry.writeUInt32LE(png.length, 8);
    entry.writeUInt32LE(offset, 12);
    directory.push(entry);
    offset += png.length;
}

fs.writeFileSync(
    path.join(root, 'public/favicon.ico'),
    Buffer.concat([header, ...directory, ...entries.map((e) => e.png)]),
);

await browser.close();
console.log(
    'public/apple-touch-icon.png と public/favicon.ico を更新しました。',
);
