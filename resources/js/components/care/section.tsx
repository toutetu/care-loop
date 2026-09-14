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

/** 数値ひとつを大きく見せる小さなカード。 */
export function StatCard({
    label,
    value,
    unit,
    hint,
    tone = 'default',
}: {
    label: string;
    value: ReactNode;
    unit?: string;
    hint?: ReactNode;
    tone?: 'default' | 'alert';
}) {
    return (
        <Card>
            <CardContent className="space-y-1 py-1">
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
        </Card>
    );
}

/** 一覧が空のときの表示。何もないのか、条件に合わないのかを書き分ける。 */
export function EmptyState({ children }: { children: ReactNode }) {
    return (
        <p className="py-6 text-center text-sm text-muted-foreground">{children}</p>
    );
}
