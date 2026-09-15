/**
 * 2色のコントラスト比（WCAG 2.x）を測る。
 *
 * 使い方:
 *   node scripts/contrast.mjs "oklch(0.44 0.09 195)" "oklch(1 0 0)"
 *   node scripts/contrast.mjs "#2fa69c" "#ffffff"
 *
 * resources/css/app.css の色を変えるときに使う。文字色は明暗どちらの
 * モードでも 7:1（AAA）以上、ベタ塗りのボタンも 7:1 を目安にしている。
 * 職員は50〜60代が中心で、AA（4.5:1）では足りない（app.css の注記）。
 *
 * oklch → sRGB の変換式は CSS Color 4 の仕様どおり。色域外の値は
 * 0〜1 に丸めるので、彩度の高い色では実際の表示と少し差が出る。
 */
function oklchToLinearRgb(L, C, h) {
    const hr = (h * Math.PI) / 180;
    const a = C * Math.cos(hr);
    const b = C * Math.sin(hr);
    const l_ = L + 0.3963377774 * a + 0.2158037573 * b;
    const m_ = L - 0.1055613458 * a - 0.0638541728 * b;
    const s_ = L - 0.0894841775 * a - 1.291485548 * b;
    const l = l_ ** 3;
    const m = m_ ** 3;
    const s = s_ ** 3;
    return [
        4.0767416621 * l - 3.3077115913 * m + 0.2309699292 * s,
        -1.2684380046 * l + 2.6097574011 * m - 0.3413193965 * s,
        -0.0041960863 * l - 0.7034186147 * m + 1.707614701 * s,
    ].map((c) => Math.min(1, Math.max(0, c)));
}

function hexToLinearRgb(hex) {
    const n = parseInt(hex.replace('#', ''), 16);
    return [n >> 16, (n >> 8) & 255, n & 255].map((v) => {
        const c = v / 255;
        return c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
    });
}

function parse(text) {
    const oklch = text.match(/oklch\(\s*([\d.]+)\s+([\d.]+)\s+([\d.]+)\s*\)/);
    if (oklch) {
        return oklchToLinearRgb(+oklch[1], +oklch[2], +oklch[3]);
    }
    if (/^#?[0-9a-f]{6}$/i.test(text)) {
        return hexToLinearRgb(text);
    }
    throw new Error(`色として読めません: ${text}`);
}

function toHex(linear) {
    return (
        '#' +
        linear
            .map((c) =>
                Math.round(
                    (c <= 0.0031308
                        ? 12.92 * c
                        : 1.055 * c ** (1 / 2.4) - 0.055) * 255,
                )
                    .toString(16)
                    .padStart(2, '0'),
            )
            .join('')
    );
}

const luminance = ([r, g, b]) => 0.2126 * r + 0.7152 * g + 0.0722 * b;

const [first, second] = process.argv.slice(2);

if (!first || !second) {
    console.error('使い方: node scripts/contrast.mjs <色1> <色2>');
    process.exit(1);
}

const a = parse(first);
const b = parse(second);
const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x);
const ratio = (hi + 0.05) / (lo + 0.05);

console.log(
    `${first} (${toHex(a)}) と ${second} (${toHex(b)}): ${ratio.toFixed(2)}:1` +
        (ratio >= 7 ? '  AAA' : ratio >= 4.5 ? '  AA' : '  不足'),
);
