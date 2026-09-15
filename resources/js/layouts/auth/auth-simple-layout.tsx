import { Link } from '@inertiajs/react';
import { BrandMark } from '@/components/brand-mark';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

/**
 * ログインなど認証画面の枠。
 *
 * 上にロゴ、その下に見出しと補足、中身の順。ロゴはトップページへの
 * リンクにしてあり、間違えて開いた人が紹介ページへ戻れるようにする。
 */
export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    return (
        <div className="bg-background flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
            <div className="w-full max-w-md">
                <div className="flex flex-col gap-8">
                    <div className="flex flex-col items-center gap-4">
                        <Link
                            href={home()}
                            className="focus-visible:ring-ring flex flex-col items-center gap-3 rounded-2xl outline-none focus-visible:ring-2"
                        >
                            <BrandMark
                                className="shadow-card size-16 rounded-2xl"
                                title=""
                            />
                            <span className="sr-only">トップページへ</span>
                        </Link>

                        <div className="space-y-1 text-center">
                            <h1 className="text-xl font-bold tracking-tight">
                                {title}
                            </h1>
                            <p className="text-muted-foreground text-center text-sm">
                                {description}
                            </p>
                        </div>
                    </div>
                    {children}
                </div>
            </div>
        </div>
    );
}
