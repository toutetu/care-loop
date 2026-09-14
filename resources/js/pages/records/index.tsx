import { Head, Link, router } from '@inertiajs/react';
import { CalendarDays, CircleCheck, Printer, X } from 'lucide-react';
import { RecordStatusBadge } from '@/components/care/badges';
import { EmptyState, Section, StatCard } from '@/components/care/section';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { dashboard } from '@/routes';
import records from '@/routes/records';
import residents from '@/routes/residents';
import type { RecordStatus } from '@/types/care';

type Row = {
    recordId: number;
    residentId: number;
    name: string;
    careLevel: string | null;
    arrivalTime: string | null;
    departureTime: string | null;
    temperature: number | null;
    recorder: string | null;
    status: RecordStatus;
    bathing: boolean | null;
    canEdit: boolean;
};

type Props = {
    day: { date: string; label: string; isToday: boolean };
    counts: { total: number; unconfirmed: number; aiDraft: number };
    onlyUnconfirmed: boolean;
    records: Row[];
};

/**
 * 記録の一覧。
 *
 * 【ダッシュボードから独立させた理由】
 * ダッシュボードは朝礼で全体を見る場所で、ここは記録を埋めていく場所である。
 * 目的が違うものを1画面に混ぜると、どちらも中途半端になる。
 *
 * 【未確定を上に並べる】
 * この画面を開く動機は「まだ終わっていないものを片付ける」ことである。
 * 確定済みが上に並んでいると、毎回スクロールして探すことになる。
 */
export default function RecordIndex({
    day,
    counts,
    onlyUnconfirmed,
    records: rows,
}: Props) {
    const go = (params: { date?: string; status?: string | null }) => {
        router.get(
            records.index().url,
            {
                date: params.date ?? day.date,
                ...(params.status ? { status: params.status } : {}),
            },
            { preserveScroll: true },
        );
    };

    return (
        <>
            <Head title="記録一覧" />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex flex-wrap items-center gap-2">
                        <CalendarDays className="size-4 text-muted-foreground" aria-hidden />
                        <h1 className="text-lg font-semibold">{day.label}</h1>
                        {/* 休業日に開くと一覧が空になるため、いつの分かを必ず書く */}
                        {!day.isToday && (
                            <Badge variant="secondary">
                                本日はご利用がないため、直近の利用日を表示しています
                            </Badge>
                        )}
                    </div>

                    <Input
                        type="date"
                        value={day.date}
                        onChange={(event) =>
                            go({
                                date: event.target.value,
                                status: onlyUnconfirmed ? 'unconfirmed' : null,
                            })
                        }
                        className="w-auto"
                        aria-label="表示する日付"
                    />
                </div>

                <div className="grid gap-4 sm:grid-cols-3">
                    <StatCard
                        label="ご利用者"
                        value={counts.total}
                        unit="名"
                        hint={onlyUnconfirmed ? '押すとすべて表示します' : undefined}
                        onClick={onlyUnconfirmed ? () => go({ status: null }) : undefined}
                    />
                    <StatCard
                        label="未確定の記録"
                        value={counts.unconfirmed}
                        unit="件"
                        tone={counts.unconfirmed > 0 ? 'alert' : 'default'}
                        hint={
                            counts.unconfirmed > 0
                                ? '押すと未確定のみ表示します'
                                : 'すべて確定済みです'
                        }
                        onClick={
                            counts.unconfirmed > 0
                                ? () => go({ status: 'unconfirmed' })
                                : undefined
                        }
                    />
                    <StatCard
                        label="AI下書き・未確認"
                        value={counts.aiDraft}
                        unit="件"
                        tone={counts.aiDraft > 0 ? 'alert' : 'default'}
                        hint="職員がまだ読んでいない生成文です"
                    />
                </div>

                <Section
                    title="記録の状況"
                    description={
                        onlyUnconfirmed
                            ? 'まだ確定していない記録だけを表示しています。'
                            : '未確定のものを上に並べています。日付を押すと記録が開きます。'
                    }
                    action={
                        onlyUnconfirmed && (
                            <Button
                                variant="secondary"
                                size="sm"
                                onClick={() => go({ status: null })}
                            >
                                <X className="size-4" aria-hidden />
                                絞り込みを解除
                            </Button>
                        )
                    }
                >
                    {rows.length === 0 ? (
                        <EmptyState>
                            {onlyUnconfirmed
                                ? '未確定の記録はありません。'
                                : 'この日のご利用記録はありません。'}
                        </EmptyState>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[820px] text-sm">
                                <thead>
                                    <tr className="border-b text-left text-xs text-muted-foreground">
                                        <th className="pb-2 font-medium">お名前</th>
                                        <th className="pb-2 font-medium">要介護度</th>
                                        <th className="pb-2 font-medium">到着／帰宅</th>
                                        <th className="pb-2 font-medium">体温</th>
                                        <th className="pb-2 font-medium">入浴</th>
                                        <th className="pb-2 font-medium">記録</th>
                                        <th className="pb-2" />
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {rows.map((row) => (
                                        <tr key={row.recordId}>
                                            <td className="py-2">
                                                <Link
                                                    href={residents.show(row.residentId)}
                                                    className="font-medium hover:underline"
                                                >
                                                    {row.name}
                                                </Link>
                                            </td>
                                            <td className="py-2 text-muted-foreground">
                                                {row.careLevel ?? '—'}
                                            </td>
                                            <td className="py-2 tabular-nums text-muted-foreground">
                                                {row.arrivalTime ?? '—'} 〜{' '}
                                                {row.departureTime ?? '—'}
                                            </td>
                                            {/* 未測定は空欄にする。0と書くと測って0だったと読める */}
                                            <td className="py-2 tabular-nums">
                                                {row.temperature !== null
                                                    ? `${row.temperature.toFixed(1)} ℃`
                                                    : '未測定'}
                                            </td>
                                            <td className="py-2 text-muted-foreground">
                                                {row.bathing === null
                                                    ? '—'
                                                    : row.bathing
                                                      ? 'あり'
                                                      : 'なし'}
                                            </td>
                                            <td className="py-2">
                                                <RecordStatusBadge status={row.status} />
                                            </td>
                                            <td className="py-2 text-right">
                                                <div className="flex justify-end gap-1">
                                                    <ConfirmAction row={row} />
                                                    <Button variant="ghost" size="sm" asChild>
                                                        <a
                                                            href={
                                                                records.familyReport(row.recordId)
                                                                    .url
                                                            }
                                                            target="_blank"
                                                            rel="noreferrer"
                                                            title="連絡帳を印刷"
                                                        >
                                                            <Printer className="size-4" aria-hidden />
                                                        </a>
                                                    </Button>
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Section>
            </div>
        </>
    );
}

/**
 * 確定への導線。
 *
 * 【一覧から一括で確定させない】
 * 中身を読まずに確定できてしまうと、AIが書いた文章が読まれないまま
 * 法定文書になる。それを防ぐことがこの機能の要点なので、
 * 必ず記録を開かせる。
 *
 * 開いたあとに何をすればよいかが分かるよう、確定欄まで案内する
 * 目印（#confirm）を付けて開く。
 */
function ConfirmAction({ row }: { row: Row }) {
    if (row.status === 'confirmed') {
        return (
            <Button variant="ghost" size="sm" asChild>
                <Link href={records.edit(row.recordId)}>
                    {row.canEdit ? '編集' : '表示'}
                </Link>
            </Button>
        );
    }

    return (
        <Button variant="outline" size="sm" asChild>
            <Link href={`${records.edit(row.recordId).url}#confirm`}>
                <CircleCheck className="size-4" aria-hidden />
                {row.status === 'ai_draft' ? '確認して確定' : '入力して確定'}
            </Link>
        </Button>
    );
}

RecordIndex.layout = {
    breadcrumbs: [
        { title: 'ダッシュボード', href: dashboard() },
        { title: '記録一覧', href: records.index() },
    ],
};
