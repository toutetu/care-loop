import { useId } from 'react';

type Point = {
    label: string;
    value: number;
};

/**
 * 推移を見るための折れ線。体重と水分摂取量に使う。
 *
 * 【ライブラリを入れていない理由】
 * 描くのは 4〜10 点の折れ線が2種類だけである。そのために描画ライブラリを
 * 足すと、依存とビルドサイズに対して見合わない。SVGで足りる。
 *
 * 【目標線を引く】
 * 数値の並びだけでは、それが良いのか悪いのかが読み取れない。
 * 水分の目標量のように基準がある項目は、線を引いて位置関係を見せる。
 */
export function TrendChart({
    points,
    target,
    unit,
    formatValue = (value) => String(value),
}: {
    points: Point[];
    target?: number;
    unit: string;
    formatValue?: (value: number) => string;
}) {
    const gradientId = useId();

    if (points.length === 0) {
        return (
            <p className="py-8 text-center text-sm text-muted-foreground">
                記録がありません。
            </p>
        );
    }

    const width = 640;
    const height = 180;
    const padding = { top: 16, right: 16, bottom: 28, left: 44 };

    const values = points.map((point) => point.value);
    const candidates = target !== undefined ? [...values, target] : values;

    // 上下に少し余白を作る。線が枠に貼りついていると増減が読み取りにくい。
    const rawMin = Math.min(...candidates);
    const rawMax = Math.max(...candidates);
    const margin = (rawMax - rawMin || 1) * 0.15;
    const min = rawMin - margin;
    const max = rawMax + margin;

    const innerWidth = width - padding.left - padding.right;
    const innerHeight = height - padding.top - padding.bottom;

    const x = (index: number) =>
        points.length === 1
            ? padding.left + innerWidth / 2
            : padding.left + (index / (points.length - 1)) * innerWidth;

    const y = (value: number) =>
        padding.top + innerHeight - ((value - min) / (max - min)) * innerHeight;

    const line = points
        .map((point, index) => `${index === 0 ? 'M' : 'L'} ${x(index)} ${y(point.value)}`)
        .join(' ');

    const area = `${line} L ${x(points.length - 1)} ${padding.top + innerHeight} L ${x(0)} ${
        padding.top + innerHeight
    } Z`;

    return (
        <div className="overflow-x-auto">
            <svg
                viewBox={`0 0 ${width} ${height}`}
                className="h-44 w-full min-w-[420px]"
                role="img"
                aria-label={`推移グラフ（単位: ${unit}）`}
            >
                <defs>
                    <linearGradient id={gradientId} x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stopColor="currentColor" stopOpacity="0.18" />
                        <stop offset="100%" stopColor="currentColor" stopOpacity="0" />
                    </linearGradient>
                </defs>

                {/* 上端・下端の目盛り。値の幅が分からないと増減の大きさが読めない */}
                {[max, min].map((value) => (
                    <g key={value}>
                        <line
                            x1={padding.left}
                            x2={width - padding.right}
                            y1={y(value)}
                            y2={y(value)}
                            className="stroke-border"
                            strokeWidth="1"
                        />
                        <text
                            x={padding.left - 6}
                            y={y(value) + 4}
                            textAnchor="end"
                            className="fill-muted-foreground text-[10px] tabular-nums"
                        >
                            {formatValue(Math.round(value * 10) / 10)}
                        </text>
                    </g>
                ))}

                {target !== undefined && (
                    <g>
                        <line
                            x1={padding.left}
                            x2={width - padding.right}
                            y1={y(target)}
                            y2={y(target)}
                            className="stroke-amber-500"
                            strokeWidth="1.5"
                            strokeDasharray="4 4"
                        />
                        <text
                            x={width - padding.right}
                            y={y(target) - 5}
                            textAnchor="end"
                            className="fill-amber-600 text-[10px]"
                        >
                            目標 {formatValue(target)}
                        </text>
                    </g>
                )}

                <g className="text-primary">
                    <path d={area} fill={`url(#${gradientId})`} />
                    <path
                        d={line}
                        fill="none"
                        stroke="currentColor"
                        strokeWidth="2"
                        strokeLinejoin="round"
                        strokeLinecap="round"
                    />
                    {points.map((point, index) => (
                        <circle
                            key={`${point.label}-${index}`}
                            cx={x(index)}
                            cy={y(point.value)}
                            r="3.5"
                            fill="currentColor"
                        />
                    ))}
                </g>

                {points.map((point, index) => (
                    <text
                        key={`label-${point.label}-${index}`}
                        x={x(index)}
                        y={height - 8}
                        textAnchor="middle"
                        className="fill-muted-foreground text-[10px]"
                    >
                        {point.label}
                    </text>
                ))}
            </svg>
        </div>
    );
}
