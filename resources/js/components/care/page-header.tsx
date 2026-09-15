import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * 画面の見出し。すべての業務画面がいちばん上にこれを置く。
 *
 * 【なぜ共通にするか】
 * 以前は各画面が h1 と補足とボタンの並びを手書きしていた。字の大きさや
 * 余白が画面ごとに少しずつ違い、ページを移るたびに見出しの位置が動いて
 * いた。ここで一度決め、画面は中身だけ渡す。
 *
 * 【構成】
 *   [アイコン] タイトル [件数・バッジ]            [操作ボタン]
 *              補足の一文
 *
 * アイコンは薄い青緑の角丸に載せる。無彩色の小さなアイコンでは
 * 見出しの目印にならなかった。
 */
export function PageHeader({
    title,
    icon: Icon,
    meta,
    description,
    actions,
    className,
}: {
    title: ReactNode;
    icon?: LucideIcon;
    /** タイトルの右に並べるもの。件数、月、状態のバッジなど。 */
    meta?: ReactNode;
    description?: ReactNode;
    /** 右端に寄せる操作。ボタンや日付の入力欄。 */
    actions?: ReactNode;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'flex flex-wrap items-start justify-between gap-3',
                className,
            )}
        >
            <div className="min-w-0 space-y-1">
                <div className="flex flex-wrap items-center gap-x-2.5 gap-y-1">
                    {Icon && (
                        <span
                            className="bg-primary/10 text-primary flex size-9 shrink-0 items-center justify-center rounded-lg"
                            aria-hidden
                        >
                            <Icon className="size-5" />
                        </span>
                    )}
                    <h1 className="text-xl font-bold tracking-tight">
                        {title}
                    </h1>
                    {meta}
                </div>
                {description && (
                    <div className="text-muted-foreground text-sm">
                        {description}
                    </div>
                )}
            </div>
            {/* スマートフォンでは操作を見出しの下の行へ落とし、幅いっぱいを
                使えるようにする。検索欄や日付の入力欄が細く潰れないため。 */}
            {actions && (
                <div className="flex flex-wrap items-center gap-2 max-sm:w-full">
                    {actions}
                </div>
            )}
        </div>
    );
}
