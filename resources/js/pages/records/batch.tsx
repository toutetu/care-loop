import { Form, Head, Link, router } from '@inertiajs/react';
import { CalendarDays } from 'lucide-react';
import BatchEntryController from '@/actions/App/Http/Controllers/BatchEntryController';
import { PageHeader } from '@/components/care/page-header';
import { EmptyState, Section } from '@/components/care/section';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';
import records from '@/routes/records';
import residentRoutes from '@/routes/residents';

type Entry = {
    id: number;
    when: string | null;
    text: string;
    recorder: string | null;
};

type Row = {
    recordId: number;
    residentId: number;
    name: string;
    careLevel: string | null;
    canEdit: boolean;
    entries: Entry[];
};

type Kind = 'bathing' | 'meal' | 'vital';

type Props = {
    kind: Kind;
    day: { date: string; label: string; isToday: boolean };
    rows: Row[];
    mealForms: string[];
    bathingTypes: { value: string; label: string }[];
};

const TITLES: Record<Kind, { title: string; description: string }> = {
    bathing: {
        title: '入浴入力',
        description:
            '介助を担当した方を続けて入力できます。入れた分は記録にそのまま残り、記録入力の画面からも見えます。',
    },
    meal: {
        title: '食事入力',
        description:
            '摂取量をまとめて入力できます。食後に入れ直しても前の記録は消えず、別の1件として残ります。',
    },
    vital: {
        title: 'バイタル入力',
        description:
            '測っていない項目は空欄のままにしてください。0と書くと、測って0だったという記録になります。',
    },
};

/**
 * 一括入力。
 *
 * 【記録入力画面と両方から入れられる】
 * 同じ事実を、ご利用者ごとの記録からも、この作業ごとの画面からも入力できる。
 * 入浴介助を終えた職員は浴室を出たところで担当した方を順に入れたいし、
 * 1人の1日をまとめて書きたい場面では記録入力画面のほうが早い。
 * 入れた先は同じ表なので、どちらから入れても同じ記録になる。
 *
 * 【入力欄は空から始める】
 * すでに入っている分はその人の行に並べる。欄に前の値を出すと、
 * 保存のたびに同じ内容をもう一度積んでしまう。
 */
export default function BatchEntry({
    kind,
    day,
    rows,
    mealForms,
    bathingTypes,
}: Props) {
    const { title, description } = TITLES[kind];

    const goToDate = (date: string) => {
        router.get(
            BatchEntryController.index.url({ kind }),
            { date },
            { preserveScroll: true },
        );
    };

    return (
        <>
            <Head title={title} />

            <div className="flex flex-col gap-4 p-4">
                <PageHeader
                    icon={CalendarDays}
                    title={day.label}
                    meta={
                        !day.isToday && (
                            <Badge variant="secondary">
                                本日はご利用がないため、直近の利用日を表示しています
                            </Badge>
                        )
                    }
                    actions={
                        <Input
                            type="date"
                            value={day.date}
                            onChange={(event) => goToDate(event.target.value)}
                            className="w-auto"
                            aria-label="表示する日付"
                        />
                    }
                />

                <Section title={title} description={description}>
                    {rows.length === 0 ? (
                        <EmptyState>
                            この日のご利用記録はありません。
                        </EmptyState>
                    ) : (
                        <Form
                            action={BatchEntryController.store.url({ kind })}
                            method="post"
                            options={{ preserveScroll: true }}
                            // 保存できたら欄を空に戻す。値が残ったままだと、
                            // もう一度押しただけで同じ内容が二重に積まれる。
                            resetOnSuccess
                        >
                            {({ processing }) => (
                                <>
                                    <ul className="flex flex-col gap-3">
                                        {rows.map((row) => (
                                            <li
                                                key={row.recordId}
                                                className="rounded-lg border p-4"
                                            >
                                                <div className="flex flex-wrap items-baseline justify-between gap-2">
                                                    <Link
                                                        href={residentRoutes.show(
                                                            row.residentId,
                                                        )}
                                                        className="font-semibold hover:underline"
                                                    >
                                                        {row.name} 様
                                                    </Link>
                                                    <Link
                                                        href={records.edit(
                                                            row.recordId,
                                                        )}
                                                        className="text-muted-foreground text-sm hover:underline"
                                                    >
                                                        記録を開く
                                                    </Link>
                                                </div>

                                                {row.entries.length > 0 && (
                                                    <ul className="text-muted-foreground mt-2 space-y-0.5 text-sm">
                                                        {row.entries.map(
                                                            (entry) => (
                                                                <li
                                                                    key={
                                                                        entry.id
                                                                    }
                                                                >
                                                                    {entry.when ??
                                                                        '時刻なし'}
                                                                    {' ／ '}
                                                                    {entry.text}
                                                                    {entry.recorder
                                                                        ? ` ／ ${entry.recorder}`
                                                                        : ''}
                                                                </li>
                                                            ),
                                                        )}
                                                    </ul>
                                                )}

                                                <div className="mt-3">
                                                    {row.canEdit ? (
                                                        <Fields
                                                            kind={kind}
                                                            recordId={
                                                                row.recordId
                                                            }
                                                            mealForms={
                                                                mealForms
                                                            }
                                                            bathingTypes={
                                                                bathingTypes
                                                            }
                                                        />
                                                    ) : (
                                                        <p className="text-muted-foreground text-sm">
                                                            この記録は編集できません。
                                                        </p>
                                                    )}
                                                </div>
                                            </li>
                                        ))}
                                    </ul>

                                    {/* 20名ぶんを入れ終えるころには先頭が見えない。
                                        保存は画面下に貼り付けておく。 */}
                                    <div className="bg-background/95 sticky bottom-0 mt-4 flex items-center justify-end rounded-lg border px-4 py-3 backdrop-blur">
                                        <Button
                                            type="submit"
                                            pending={processing}
                                        >
                                            {processing
                                                ? '保存しています…'
                                                : 'まとめて保存'}
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    )}
                </Section>
            </div>
        </>
    );
}

/**
 * 1名ぶんの入力欄。
 *
 * name を entries[記録ID][項目] にすることで、サーバー側は記録IDごとの
 * 連想配列として受け取れる。行の順番に依存しないので、並べ替えても壊れない。
 */
function Fields({
    kind,
    recordId,
    mealForms,
    bathingTypes,
}: {
    kind: Kind;
    recordId: number;
    mealForms: string[];
    bathingTypes: { value: string; label: string }[];
}) {
    const field = (name: string) => `entries[${recordId}][${name}]`;

    if (kind === 'bathing') {
        return (
            <div className="grid gap-2 sm:max-w-xs">
                <Label htmlFor={`bathing-${recordId}`}>入浴・清拭</Label>
                {/*
                 * ネイティブの select を使う。行が20個並ぶ画面では、
                 * Radix の Select を人数ぶん置くとレンダリングが重くなり、
                 * スマートフォンで操作が遅れる。
                 */}
                <select
                    id={`bathing-${recordId}`}
                    name={field('bathing_type')}
                    defaultValue=""
                    className="border-input bg-background focus-visible:ring-ring h-11 rounded-md border px-3 text-base shadow-xs outline-none focus-visible:ring-[3px] md:text-sm"
                >
                    <option value="">入力しない</option>
                    {bathingTypes.map((type) => (
                        <option key={type.value} value={type.value}>
                            {type.label}
                        </option>
                    ))}
                </select>
            </div>
        );
    }

    if (kind === 'meal') {
        return (
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div className="grid gap-2">
                    <Label htmlFor={`meal-type-${recordId}`}>区分</Label>
                    <select
                        id={`meal-type-${recordId}`}
                        name={field('meal_type')}
                        defaultValue="lunch"
                        className="border-input bg-background focus-visible:ring-ring h-11 rounded-md border px-3 text-base shadow-xs outline-none focus-visible:ring-[3px] md:text-sm"
                    >
                        <option value="lunch">昼食</option>
                        <option value="snack">おやつ</option>
                    </select>
                </div>
                <div className="grid gap-2">
                    <Label htmlFor={`staple-${recordId}`}>主食（%）</Label>
                    <Input
                        id={`staple-${recordId}`}
                        name={field('staple_rate')}
                        type="number"
                        inputMode="numeric"
                        min={0}
                        max={100}
                        defaultValue=""
                    />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor={`side-${recordId}`}>副菜（%）</Label>
                    <Input
                        id={`side-${recordId}`}
                        name={field('side_rate')}
                        type="number"
                        inputMode="numeric"
                        min={0}
                        max={100}
                        defaultValue=""
                    />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor={`form-${recordId}`}>食形態</Label>
                    <select
                        id={`form-${recordId}`}
                        name={field('meal_form')}
                        defaultValue=""
                        className="border-input bg-background focus-visible:ring-ring h-11 rounded-md border px-3 text-base shadow-xs outline-none focus-visible:ring-[3px] md:text-sm"
                    >
                        <option value="">指定しない</option>
                        {mealForms.map((form) => (
                            <option key={form} value={form}>
                                {form}
                            </option>
                        ))}
                    </select>
                </div>
                <label className="flex min-h-11 items-center gap-3 text-sm">
                    <Checkbox name={field('choking')} value="1" />
                    むせ込みがあった
                </label>
            </div>
        );
    }

    return (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div className="grid gap-2">
                <Label htmlFor={`temp-${recordId}`}>体温（℃）</Label>
                <Input
                    id={`temp-${recordId}`}
                    name={field('temperature')}
                    type="number"
                    step="0.1"
                    inputMode="decimal"
                    defaultValue=""
                />
            </div>
            <div className="grid gap-2">
                <Label htmlFor={`sbp-${recordId}`}>収縮期血圧</Label>
                <Input
                    id={`sbp-${recordId}`}
                    name={field('systolic_bp')}
                    type="number"
                    inputMode="numeric"
                    defaultValue=""
                />
            </div>
            <div className="grid gap-2">
                <Label htmlFor={`dbp-${recordId}`}>拡張期血圧</Label>
                <Input
                    id={`dbp-${recordId}`}
                    name={field('diastolic_bp')}
                    type="number"
                    inputMode="numeric"
                    defaultValue=""
                />
            </div>
            <div className="grid gap-2">
                <Label htmlFor={`spo2-${recordId}`}>SpO2（%）</Label>
                <Input
                    id={`spo2-${recordId}`}
                    name={field('spo2')}
                    type="number"
                    inputMode="numeric"
                    defaultValue=""
                />
            </div>
        </div>
    );
}

BatchEntry.layout = {
    breadcrumbs: [
        { title: 'ダッシュボード', href: dashboard() },
        { title: '記録一覧', href: records.index() },
    ],
};
