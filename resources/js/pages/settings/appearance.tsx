import { Head } from '@inertiajs/react';
import AppearanceTabs from '@/components/appearance-tabs';
import Heading from '@/components/heading';
import { edit as editAppearance } from '@/routes/appearance';

export default function Appearance() {
    return (
        <>
            <Head title="表示の設定" />

            <h1 className="sr-only">表示の設定</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="表示の設定"
                    description="画面の配色を切り替えられます"
                />
                <AppearanceTabs />
            </div>
        </>
    );
}

Appearance.layout = {
    breadcrumbs: [
        {
            title: '表示の設定',
            href: editAppearance(),
        },
    ],
};
