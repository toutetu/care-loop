import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * 紹介ページの1区画。番号つきの見出しと導入文を持つ。
 *
 * 【なぜ番号を振るか】
 * 紹介ページは「なぜ → どう → 何を」の順に読ませる構成で、上から順に
 * 読んでほしい。番号があると、途中から開いた人も自分がどこにいるか分かる。
 * ページ上部の目次と id で対応させてある。
 *
 * band を渡すと薄い色の帯になる。長いページで区画の切れ目を作るため。
 */
export function IntroSection({
    id,
    number,
    label,
    title,
    lead,
    band = false,
    children,
}: {
    id: string;
    number: string;
    /** 目次と同じ短い名前。見出しの上に小さく出す。 */
    label: string;
    title: string;
    lead?: ReactNode;
    band?: boolean;
    children: ReactNode;
}) {
    return (
        <section
            id={id}
            aria-labelledby={`${id}-title`}
            className={cn(
                'scroll-mt-20 py-16 sm:py-20',
                band && 'bg-sidebar/50 border-y',
            )}
        >
            <div className="mx-auto max-w-5xl px-6">
                <header className="max-w-3xl">
                    <p className="text-primary flex items-center gap-2 text-sm font-bold tracking-wide">
                        <span className="font-mono">{number}</span>
                        <span aria-hidden>／</span>
                        {label}
                    </p>
                    <h2
                        id={`${id}-title`}
                        className="mt-2 text-2xl font-bold tracking-tight sm:text-3xl"
                    >
                        {title}
                    </h2>
                    {lead && (
                        <div className="text-muted-foreground mt-4 space-y-3 leading-relaxed">
                            {lead}
                        </div>
                    )}
                </header>
                <div className="mt-10">{children}</div>
            </div>
        </section>
    );
}
