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
                        <p className="text-sm text-muted-foreground">{description}</p>
                    )}
                </div>
                {action}
            </CardHeader>
            <CardContent>{children}</CardContent>
        </Card>
    );
}

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
}: {
    label: string;
    value: ReactNode;
    unit?: string;
    hint?: ReactNode;
    tone?: 'default' | 'alert';
    href?: NonNullable<InertiaLinkProps['href']>;
    onClick?: () => void;
}) {
    const body = (
        <CardContent className="space-y-1 py-1 text-left">
            <p className="text-sm text-muted-foreground">{label}</p>
            <p
                className={cn(
                    'text-2xl font-semibold tabular-nums',
                    tone === 'alert' && 'text-red-600 dark:text-red-400',
                )}
            >
                {value}
                {unit && <span className="ml-1 text-sm font-normal">{unit}</span>}
            </p>
            {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
        </CardContent>
    );

    const interactive =
        'h-full transition-colors hover:border-primary/40 hover:bg-accent/40';

    if (href !== undefined) {
        return (
            <Link
                href={href}
                className="rounded-xl outline-none focus-visible:ring-2 focus-visible:ring-ring"
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
                className="w-full rounded-xl text-left outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
                <Card className={interactive}>{body}</Card>
            </button>
        );
    }

    return <Card>{body}</Card>;
}

/** 一覧が空のときの表示。何もないのか、条件に合わないのかを書き分ける。 */
export function EmptyState({ children }: { children: ReactNode }) {
    return (
        <p className="py-6 text-center text-sm text-muted-foreground">{children}</p>
    );
}
