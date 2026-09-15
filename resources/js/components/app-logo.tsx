import { usePage } from '@inertiajs/react';
import { BrandMark } from '@/components/brand-mark';

/**
 * サイドバー左上のロゴ。マークとアプリ名を横に並べる。
 * サイドバーを畳むとアプリ名は隠れ、マークだけが残る（ui/sidebar の仕様）。
 */
export default function AppLogo() {
    const { name } = usePage().props;

    return (
        <>
            <BrandMark className="size-8 rounded-lg" title="" />
            <div className="ml-1 grid flex-1 text-left text-sm">
                <span className="mb-0.5 truncate leading-tight font-bold tracking-tight">
                    {name}
                </span>
            </div>
        </>
    );
}
