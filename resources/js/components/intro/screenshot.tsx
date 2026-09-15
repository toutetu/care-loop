import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * 実際の画面の写真。PC はブラウザ風の枠、スマートフォンは端末風の枠に入れる。
 *
 * 写真は scripts/capture-screenshots.mjs が public/images/intro/ に出す。
 * width / height は撮影時の CSS ピクセル（PC 1280×800、スマートフォン
 * 390×844）で、読み込み前に高さを確保して、文章がずれないようにする。
 *
 * 写真は明モードで撮っている。暗モードのページに載せると眩しいので、
 * 枠の中を少し暗くして馴染ませる。
 */
export function Screenshot({
    src,
    alt,
    caption,
    kind = 'desktop',
    priority = false,
    className,
}: {
    src: string;
    alt: string;
    caption?: ReactNode;
    kind?: 'desktop' | 'mobile';
    /** 最初に見える写真は遅延読み込みにしない。 */
    priority?: boolean;
    className?: string;
}) {
    const size =
        kind === 'desktop'
            ? { width: 1280, height: 800 }
            : { width: 390, height: 844 };

    const image = (
        <img
            src={src}
            alt={alt}
            width={size.width}
            height={size.height}
            loading={priority ? 'eager' : 'lazy'}
            decoding="async"
            className="block h-auto w-full dark:brightness-90"
        />
    );

    return (
        <figure className={cn('space-y-3', className)}>
            {kind === 'desktop' ? (
                <div className="bg-card shadow-card overflow-hidden rounded-xl border">
                    <div
                        className="bg-muted/60 flex items-center gap-1.5 border-b px-3 py-2"
                        aria-hidden
                    >
                        <span className="bg-border size-2.5 rounded-full" />
                        <span className="bg-border size-2.5 rounded-full" />
                        <span className="bg-border size-2.5 rounded-full" />
                    </div>
                    {image}
                </div>
            ) : (
                <div className="bg-foreground/85 border-foreground/85 shadow-card mx-auto max-w-[300px] overflow-hidden rounded-[2rem] border-[6px]">
                    <div className="overflow-hidden rounded-[1.6rem]">
                        {image}
                    </div>
                </div>
            )}
            {caption && (
                <figcaption className="text-muted-foreground text-center text-sm">
                    {caption}
                </figcaption>
            )}
        </figure>
    );
}
