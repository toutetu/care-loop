import { Head, Link } from '@inertiajs/react';
import { CircleAlert, Search } from 'lucide-react';
import { useMemo, useState } from 'react';
import { EmptyState } from '@/components/care/section';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { dashboard } from '@/routes';
import residentRoutes from '@/routes/residents';

type Resident = {
    id: number;
    name: string;
    nameKana: string;
    age: number | null;
    gender: string;
    careLevel: string | null;
    weekdays: string[];
    nextVisit: string | null;
    unreviewedRiskCount: number;
};

type Props = {
    residents: Resident[];
};

/**
 * ご利用者の一覧。
 *
 * 【絞り込みを画面側で行っている理由】
 * 氏名とカナは暗号化して保存しているため、SQLの LIKE 検索ができない
 * （要件定義 9.3節）。1事業所のご利用者は定員に収まる規模なので、
 * 全件を渡して画面側で絞り込むほうが速く、実装も単純になる。
 *
 * カナの完全一致検索だけはブラインドインデックスでDB側から引ける仕組みを
 * 用意してあるが（Resident::scopeWhereKana）、この画面の用途は
 * 「一覧から目的の方を探す」ことなので、部分一致のほうが合う。
 */
export default function ResidentIndex({ residents }: Props) {
    const [keyword, setKeyword] = useState('');

    const filtered = useMemo(() => {
        const query = keyword.trim();

        if (query === '') {
            return residents;
        }

        return residents.filter(
            (resident) =>
                resident.name.includes(query) ||
                resident.nameKana.includes(query) ||
                (resident.careLevel ?? '').includes(query),
        );
    }, [keyword, residents]);

    return (
        <>
            <Head title="利用者一覧" />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h1 className="text-lg font-semibold">
                        利用者一覧
                        <span className="ml-2 text-sm font-normal text-muted-foreground">
                            {filtered.length} 名
                        </span>
                    </h1>

                    <div className="relative w-full sm:w-72">
                        <Search
                            className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                            aria-hidden
                        />
                        <Input
                            value={keyword}
                            onChange={(event) => setKeyword(event.target.value)}
                            placeholder="お名前・カナ・要介護度で絞り込む"
                            className="pl-9"
                            aria-label="ご利用者の絞り込み"
                        />
                    </div>
                </div>

                {filtered.length === 0 ? (
                    <Card>
                        <CardContent>
                            <EmptyState>
                                「{keyword}」に一致するご利用者はいません。
                            </EmptyState>
                        </CardContent>
                    </Card>
                ) : (
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        {filtered.map((resident) => (
                            <Link
                                key={resident.id}
                                href={residentRoutes.show(resident.id)}
                                className="rounded-xl outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            >
                                <Card className="h-full transition-colors hover:border-primary/40 hover:bg-accent/40">
                                    <CardContent className="space-y-3 py-1">
                                        <div className="flex items-start justify-between gap-2">
                                            <div className="min-w-0">
                                                <p className="truncate font-medium">
                                                    {resident.name} 様
                                                </p>
                                                <p className="truncate text-xs text-muted-foreground">
                                                    {resident.nameKana}
                                                </p>
                                            </div>
                                            {resident.unreviewedRiskCount > 0 && (
                                                <Badge
                                                    variant="outline"
                                                    className="shrink-0 gap-1 border-transparent bg-red-600 text-white dark:bg-red-700"
                                                >
                                                    <CircleAlert className="size-3" aria-hidden />
                                                    要確認
                                                </Badge>
                                            )}
                                        </div>

                                        <dl className="grid grid-cols-2 gap-y-1 text-sm">
                                            <dt className="text-muted-foreground">年齢</dt>
                                            <dd className="tabular-nums">
                                                {resident.age !== null
                                                    ? `${resident.age}歳（${resident.gender}）`
                                                    : resident.gender}
                                            </dd>

                                            <dt className="text-muted-foreground">要介護度</dt>
                                            <dd>{resident.careLevel ?? '—'}</dd>

                                            <dt className="text-muted-foreground">利用曜日</dt>
                                            <dd>
                                                {resident.weekdays.length > 0
                                                    ? resident.weekdays.join('・')
                                                    : '—'}
                                            </dd>

                                            <dt className="text-muted-foreground">次回</dt>
                                            <dd>{resident.nextVisit ?? '—'}</dd>
                                        </dl>
                                    </CardContent>
                                </Card>
                            </Link>
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

ResidentIndex.layout = {
    breadcrumbs: [
        { title: 'ダッシュボード', href: dashboard() },
        { title: '利用者一覧', href: residentRoutes.index() },
    ],
};
