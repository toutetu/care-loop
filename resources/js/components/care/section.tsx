import { Link } from '@inertiajs/react';
import type { InertiaLinkProps } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { cn } from '@/lib/utils';

/** 見出しと補足、右側の操作ボタンを持つカード。画面共通の枠。 */
export function Section({
    title,
    description,
    action,
    children,
    className,
}: {
    title: string;
    description?: ReactNode;
    action?: ReactNode;
    children: ReactNode;
    className?: string;
}) {
    return (
        <Card className={className}>
            <CardHeader className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0 space-y-1">
                    <CardTitle className="text-base">{title}</CardTitle>
                    {description && (
                        <p className="text-muted-foreground text-sm">
                            {description}
                        </p>
                    )}
                </div>
                {action}
            </CardHeader>
            <CardContent>{children}</CardContent>
        </Card>
    );
}

/** 数値カードの色。default 以外は app.css の意味色に対応する。 */
export type StatTone = 'default' | 'danger' | 'warning' | 'ai';

const STAT_TONE: Record<
    Exclude<StatTone, 'default'>,
    { surface: string; hover: string; value: string }
> = {
    danger: {
        surface: 'border-danger-line bg-danger-soft',
        hover: 'hover:bg-danger-soft/70',
        value: 'text-danger-ink',
    },
    warning: {
        surface: 'border-warning-line bg-warning-soft',
        hover: 'hover:bg-warning-soft/70',
        value: 'text-warning-ink',
    },
    ai: {
        surface: 'border-ai-line bg-ai-soft',
        hover: 'hover:bg-ai-soft/70',
        value: 'text-ai-ink',
    },
};

/**
 * 数値ひとつを大きく見せる小さなカード。
 *
 * 数字を見た職員が次にすることは「その中身を見る」である。
 * そこへ行けないカードは行き止まりになるため、href か onClick を渡せば
 * 押せるようにしてある。
 *
 * 別の画面へ移るなら href（リンク）、同じ画面で絞り込むなら onClick を使う。
 * 画面移動をボタンで作ると、新しいタブで開けず、URLも共有できない。
 */
export function StatCard({
    label,
    value,
    unit,
    hint,
    tone = 'default',
    href,
    onClick,
    className,
}: {
    label: string;
    value: ReactNode;
    unit?: string;
    hint?: ReactNode;
    tone?: StatTone;
    href?: NonNullable<InertiaLinkProps['href']>;
    onClick?: () => void;
    /** グリッドの中での占め方を、呼び出し側から決めたいときに使う。 */
    className?: string;
}) {
    const body = (
        <CardContent className="space-y-1 py-1 text-left">
            <p className="text-muted-foreground text-sm">{label}</p>
            <p
                className={cn(
                    'text-3xl font-bold tracking-tight tabular-nums',
                    tone !== 'default' && STAT_TONE[tone].value,
                )}
            >
                {value}
                {unit && (
                    <span className="ml-1 text-sm font-normal">{unit}</span>
                )}
            </p>
            {hint && <p className="text-muted-foreground text-xs">{hint}</p>}
        </CardContent>
    );

    /*
     * 色つきの tone は面ごと淡く塗る。数字の色を変えるだけでは、
     * 4枚並んだカードの中で目に入らなかった。
     *
     * 色の意味は app.css の意味色と同じ。danger は今日中に見るもの、
     * warning は放置しないもの、ai は人が目で確かめるもの。
     * 以前は全部を赤にしていたが、未確定の記録やAIの失敗まで赤だと
     * 4枚中3枚が赤くなり、本当に急ぐものが埋もれた。
     */
    const surface = tone === 'default' ? undefined : STAT_TONE[tone].surface;

    const interactive = cn(
        'hover:border-primary/40 h-full transition-colors',
        tone === 'default' ? 'hover:bg-accent/40' : STAT_TONE[tone].hover,
        surface,
    );

    if (href !== undefined) {
        return (
            <Link
                href={href}
                className={cn(
                    'focus-visible:ring-ring rounded-xl outline-none focus-visible:ring-2',
                    className,
                )}
            >
                <Card className={interactive}>{body}</Card>
            </Link>
        );
    }

    if (onClick !== undefined) {
        // div に onClick を付けるのではなく button で包む。
        // キーボードでも押せて、読み上げにも操作として伝わる。
        return (
            <button
                type="button"
                onClick={onClick}
                className={cn(
                    'focus-visible:ring-ring w-full rounded-xl text-left outline-none focus-visible:ring-2',
                    className,
                )}
            >
                <Card className={interactive}>{body}</Card>
            </button>
        );
    }

    return <Card className={cn(surface, className)}>{body}</Card>;
}

/** 一覧が空のときの表示。何もないのか、条件に合わないのかを書き分ける。 */
export function EmptyState({ children }: { children: ReactNode }) {
    return (
        <p className="text-muted-foreground py-6 text-center text-sm">
            {children}
        </p>
    );
}
