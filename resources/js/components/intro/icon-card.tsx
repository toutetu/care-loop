import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * 絵柄つきの説明カード。課題・使った場面・重視した点を並べるときの単位。
 *
 * tone は絵柄の色だけを変える。brand は青緑、ai は紫（AI が出したもの、
 * という app.css の意味色と同じ）、warning は黄（課題）。
 * 面の色は変えない。カードが4枚並んだとき、面まで色を変えると
 * 読む順番が分からなくなる。
 */
const ICON_TONE = {
    brand: 'bg-primary/10 text-primary',
    ai: 'bg-ai-soft text-ai-ink',
    warning: 'bg-warning-soft text-warning-ink',
} as const;

export function IconCard({
    icon: Icon,
    title,
    tone = 'brand',
    eyebrow,
    children,
    className,
}: {
    icon: LucideIcon;
    title: string;
    tone?: keyof typeof ICON_TONE;
    /** 見出しの上に添える小さな文字。「課題1」「F-LLM-05」など。 */
    eyebrow?: string;
    children: ReactNode;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'bg-card shadow-card flex flex-col gap-3 rounded-xl border p-5',
                className,
            )}
        >
            <div className="flex items-center gap-3">
                <span
                    className={cn(
                        'flex size-11 shrink-0 items-center justify-center rounded-lg',
                        ICON_TONE[tone],
                    )}
                    aria-hidden
                >
                    <Icon className="size-6" />
                </span>
                <div className="min-w-0">
                    {eyebrow && (
                        <p className="text-muted-foreground text-xs font-bold tracking-wide">
                            {eyebrow}
                        </p>
                    )}
                    <h3 className="font-bold">{title}</h3>
                </div>
            </div>
            <div className="text-muted-foreground text-sm leading-relaxed">
                {children}
            </div>
        </div>
    );
}
