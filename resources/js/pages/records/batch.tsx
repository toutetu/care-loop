import { Form, Head, Link, router } from '@inertiajs/react';
import { CalendarDays } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import BatchEntryController from '@/actions/App/Http/Controllers/BatchEntryController';
import { ChoiceToggle } from '@/components/care/choice-toggle';
import { PageHeader } from '@/components/care/page-header';
import { EmptyState, Section } from '@/components/care/section';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
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

type BathingType = { value: string; label: string; short: string };

type Props = {
    kind: Kind;
    day: { date: string; label: string; isToday: boolean };
    rows: Row[];
    mealForms: string[];
    bathingTypes: BathingType[];
};

const TITLES: Record<Kind, { title: string; description: string }> = {
    bathing: {
        title: '入浴入力',
        description:
            '担当した方の行を押して、実施した内容を選んでください。押していない行は記録を作りません。「実施なし」は入浴も清拭も行わなかったことで、入力しなかったこととは違います。',
    },
    meal: {
        title: '食事入力',
        description:
            '区分を選んでから、摂取量をまとめて入力できます。食後に入れ直しても前の記録は消えず、別の1件として残ります。',
    },
    vital: {
        title: 'バイタル入力',
        description:
            '測っていない項目は空欄のままにしてください。0と書くと、測って0だったという記録になります。',
    },
};

/** 昼食とおやつ。表ぜんぶに1つだけ効く。 */
const MEAL_TYPES = [
    { value: 'lunch', label: '昼食' },
    { value: 'snack', label: 'おやつ' },
];

/**
 * 一括入力。
 *
 * 【記録入力画面と両方から入れられる】
 * 同じ事実を、ご利用者ごとの記録からも、この作業ごとの画面からも入力できる。
 * 入浴介助を終えた職員は浴室を出たところで担当した方を順に入れたいし、
 * 1人の1日をまとめて書きたい場面では記録入力画面のほうが早い。
 * 入れた先は同じ表なので、どちらから入れても同じ記録になる。
 *
 * 【なぜ表にしたか】
 * 1人1枚のカードで並べていたときは、20名ぶんで4000px近くになり、
 * タブレットでは何度も指を滑らせないと最後の人まで届かなかった。
 * 入れる項目は全員同じなので、縦に積む必然性がない。1人1行の表にすると
 * 同じ20名が1100px程度に収まり、見出しと保存ボタンも画面から消えない。
 *
 * 【入力欄は空から始める】
 * すでに入っている分はその人の行に出す。欄に前の値を出すと、
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

    /*
     * 保存できたら入力欄を空へ戻す。
     *
     * ネイティブの入力欄は resetOnSuccess が戻すが、トグルは React の状態で
     * 値を持っているので戻らない。表の中身ごと作り直して初期値に返す。
     * 戻さないと、もう一度押しただけで同じ内容が二重に積まれる。
     */
    const [sheetKey, setSheetKey] = useState(0);

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
                            resetOnSuccess
                            onSuccess={() => setSheetKey((key) => key + 1)}
                            className="flex flex-col gap-3"
                        >
                            {({ processing }) => (
                                <>
                                    {/* 昼食を全員ぶん入れ、そのあとおやつを
                                        全員ぶん入れる。行ごとに選ばせると
                                        同じ値を20回選ぶことになる。 */}
                                    {kind === 'meal' && (
                                        <div className="flex flex-wrap items-center gap-3">
                                            <span className="text-sm font-medium">
                                                区分
                                            </span>
                                            <MealTypePicker />
                                            <span className="text-muted-foreground text-sm">
                                                この表ぜんぶに適用されます
                                            </span>
                                        </div>
                                    )}

                                    {/*
                                     * 表だけを縦にスクロールさせる。ページごと
                                     * 動かすと、列の見出しと保存ボタンが画面の
                                     * 外へ出てしまい、いま何を入れている列なのか
                                     * 分からなくなる。
                                     */}
                                    <div className="max-h-[65vh] overflow-auto rounded-lg border">
                                        <table
                                            className={cn(
                                                'w-full border-separate border-spacing-0 text-sm',
                                                SHEETS[kind].minWidth,
                                            )}
                                        >
                                            <thead>
                                                <tr>
                                                    <HeadCell first>
                                                        ご利用者
                                                    </HeadCell>
                                                    {SHEETS[kind].columns.map(
                                                        (column) => (
                                                            <HeadCell
                                                                key={
                                                                    column.label
                                                                }
                                                                width={
                                                                    column.width
                                                                }
                                                            >
                                                                {column.label}
                                                            </HeadCell>
                                                        ),
                                                    )}
                                                    <HeadCell width="w-48">
                                                        入力済み
                                                    </HeadCell>
                                                </tr>
                                            </thead>

                                            <tbody key={sheetKey}>
                                                {rows.map((row) => (
                                                    <tr
                                                        key={row.recordId}
                                                        className="hover:bg-muted/40"
                                                    >
                                                        <NameCell row={row} />

                                                        {row.canEdit ? (
                                                            <Fields
                                                                kind={kind}
                                                                row={row}
                                                                mealForms={
                                                                    mealForms
                                                                }
                                                                bathingTypes={
                                                                    bathingTypes
                                                                }
                                                            />
                                                        ) : (
                                                            <BodyCell
                                                                colSpan={
                                                                    SHEETS[kind]
                                                                        .columns
                                                                        .length
                                                                }
                                                            >
                                                                <span className="text-muted-foreground">
                                                                    この記録は編集できません
                                                                </span>
                                                            </BodyCell>
                                                        )}

                                                        <BodyCell>
                                                            <EntryList
                                                                entries={
                                                                    row.entries
                                                                }
                                                            />
                                                        </BodyCell>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>

                                    <div className="flex items-center justify-between gap-3">
                                        <p className="text-muted-foreground text-sm">
                                            {rows.length} 名
                                        </p>
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

/* ------------------------------------------------------------------ *
 * 表の骨組み
 * ------------------------------------------------------------------ */

/**
 * 表の作り。種別ごとの列と、これ以上は縮めない幅を持つ。
 *
 * 見出しと入力欄で列数がずれると表が崩れるので、数はここを正本にする。
 *
 * 【縮めずに横へスクロールさせる】
 * 幅が足りないときに列を詰めると、見出しが1文字ずつ縦に折り返り、
 * 入力欄は矢印しか見えない大きさになる。読めない表になるくらいなら、
 * 横へ動かしてもらう。氏名の列は貼り付けてあるので、動かしても
 * 誰の行かは見失わない。
 */
const SHEETS: Record<
    Kind,
    { minWidth: string; columns: { label: string; width: string }[] }
> = {
    bathing: {
        minWidth: 'min-w-[42rem]',
        columns: [{ label: '実施した内容', width: 'w-[19rem]' }],
    },
    meal: {
        minWidth: 'min-w-[47rem]',
        columns: [
            { label: '主食（%）', width: 'w-24' },
            { label: '副菜（%）', width: 'w-24' },
            { label: '食形態', width: 'w-32' },
            { label: 'むせ込み', width: 'w-20' },
        ],
    },
    vital: {
        minWidth: 'min-w-[47rem]',
        columns: [
            { label: '体温（℃）', width: 'w-24' },
            { label: '血圧（上）', width: 'w-24' },
            { label: '血圧（下）', width: 'w-24' },
            { label: 'SpO2（%）', width: 'w-24' },
        ],
    },
};

/*
 * 見出しは上へ、氏名は左へ貼り付ける。
 *
 * border-separate にしてあるので、行の下線は cell の border-b で引く。
 * collapse だと、貼り付けた cell の枠だけが一緒に動かずに消える。
 */
function HeadCell({
    children,
    first = false,
    width,
}: {
    children: ReactNode;
    first?: boolean;
    width?: string;
}) {
    return (
        <th
            scope="col"
            className={cn(
                'bg-card sticky top-0 z-20 border-b px-3 py-2 text-left font-medium whitespace-nowrap',
                first && 'left-0 z-30',
                width,
            )}
        >
            {children}
        </th>
    );
}

function BodyCell({
    children,
    colSpan,
}: {
    children: ReactNode;
    colSpan?: number;
}) {
    return (
        <td colSpan={colSpan} className="border-b px-3 py-2 align-middle">
            {children}
        </td>
    );
}

/** 氏名の列。横へスクロールしても、誰の行かを見失わないように貼り付ける。 */
function NameCell({ row }: { row: Row }) {
    return (
        <th
            scope="row"
            className="bg-card sticky left-0 z-10 border-b px-3 py-2 text-left font-normal whitespace-nowrap"
        >
            <Link
                href={residentRoutes.show(row.residentId)}
                className="font-semibold hover:underline"
            >
                {row.name} 様
            </Link>
            {/* 狭い画面では隠す。氏名の列が広がるほど、入力欄へ届くまでに
                横へ動かす距離が伸びる。記録は記録一覧からも開ける。 */}
            <Link
                href={records.edit(row.recordId)}
                className="text-muted-foreground ml-2 hidden text-xs hover:underline sm:inline"
            >
                記録
            </Link>
        </th>
    );
}

/**
 * すでに入っている分。1件も無い日のほうが多いので、無いときは詰める。
 *
 * 1件を1行に収める。折り返すと行の高さが人によって変わり、
 * 表を目で追うときに視線が上下に振られる。入りきらない分は
 * ポインタを乗せれば全文が出る。
 */
function EntryList({ entries }: { entries: Entry[] }) {
    if (entries.length === 0) {
        return <span className="text-muted-foreground text-xs">—</span>;
    }

    return (
        <ul className="text-muted-foreground max-w-56 space-y-0.5 text-xs">
            {entries.map((entry) => {
                const text = [
                    entry.when ?? '時刻なし',
                    entry.text,
                    entry.recorder,
                ]
                    .filter(Boolean)
                    .join(' ／ ');

                return (
                    <li key={entry.id} className="truncate" title={text}>
                        {text}
                    </li>
                );
            })}
        </ul>
    );
}

/* ------------------------------------------------------------------ *
 * 入力欄
 * ------------------------------------------------------------------ */

/** 表ぜんぶに効く食事の区分。 */
function MealTypePicker() {
    const [value, setValue] = useState('lunch');

    return (
        <>
            <input type="hidden" name="meal_type" value={value} />
            <div className="flex gap-2">
                {MEAL_TYPES.map((type) => (
                    <Button
                        key={type.value}
                        type="button"
                        size="sm"
                        // neutral は「押しても何も起きない」を表す色なので
                        // 使わない。選べるほうは outline で押せるまま見せる。
                        variant={value === type.value ? 'default' : 'outline'}
                        aria-pressed={value === type.value}
                        onClick={() => setValue(type.value)}
                    >
                        {type.label}
                    </Button>
                ))}
            </div>
        </>
    );
}

/**
 * 1名ぶんの入力欄。種別ごとに列の中身だけを返す。
 *
 * name を entries[記録ID][項目] にすることで、サーバー側は記録IDごとの
 * 連想配列として受け取れる。行の順番に依存しないので、並べ替えても壊れない。
 */
function Fields({
    kind,
    row,
    mealForms,
    bathingTypes,
}: {
    kind: Kind;
    row: Row;
    mealForms: string[];
    bathingTypes: BathingType[];
}) {
    const field = (name: string) => `entries[${row.recordId}][${name}]`;

    if (kind === 'bathing') {
        return (
            <BodyCell>
                <ChoiceToggle
                    name={field('bathing_type')}
                    ariaLabel={`${row.name} 様の入浴・清拭`}
                    choices={bathingTypes.map((type) => ({
                        value: type.value,
                        label: type.short,
                        fullLabel: type.label,
                    }))}
                />
            </BodyCell>
        );
    }

    if (kind === 'meal') {
        return (
            <>
                <BodyCell>
                    <RateInput
                        name={field('staple_rate')}
                        label={`${row.name} 様の主食の摂取割合`}
                    />
                </BodyCell>
                <BodyCell>
                    <RateInput
                        name={field('side_rate')}
                        label={`${row.name} 様の副菜の摂取割合`}
                    />
                </BodyCell>
                <BodyCell>
                    <NativeSelect
                        name={field('meal_form')}
                        label={`${row.name} 様の食形態`}
                    >
                        <option value="">指定しない</option>
                        {mealForms.map((form) => (
                            <option key={form} value={form}>
                                {form}
                            </option>
                        ))}
                    </NativeSelect>
                </BodyCell>
                <BodyCell>
                    {/* 押す的を44pxにする。手袋の指で隣の行を押さないため。 */}
                    <label className="flex min-h-11 items-center justify-center">
                        <Checkbox
                            name={field('choking')}
                            value="1"
                            aria-label={`${row.name} 様のむせ込み`}
                        />
                    </label>
                </BodyCell>
            </>
        );
    }

    return (
        <>
            <BodyCell>
                <NumberInput
                    name={field('temperature')}
                    label={`${row.name} 様の体温`}
                    step="0.1"
                    inputMode="decimal"
                />
            </BodyCell>
            <BodyCell>
                <NumberInput
                    name={field('systolic_bp')}
                    label={`${row.name} 様の収縮期血圧`}
                />
            </BodyCell>
            <BodyCell>
                <NumberInput
                    name={field('diastolic_bp')}
                    label={`${row.name} 様の拡張期血圧`}
                />
            </BodyCell>
            <BodyCell>
                <NumberInput
                    name={field('spo2')}
                    label={`${row.name} 様のSpO2`}
                />
            </BodyCell>
        </>
    );
}

/**
 * 表の中の数値欄。
 *
 * 見出しが列に出ているので、欄ごとの Label は置かない。代わりに
 * aria-label で誰の何かを持たせる。読み上げでは列見出しだけでは
 * 「誰の体温か」が分からない。
 */
function NumberInput({
    name,
    label,
    step,
    inputMode = 'numeric',
}: {
    name: string;
    label: string;
    step?: string;
    inputMode?: 'numeric' | 'decimal';
}) {
    return (
        <Input
            name={name}
            type="number"
            step={step}
            inputMode={inputMode}
            aria-label={label}
            defaultValue=""
            className="w-full min-w-16"
        />
    );
}

function RateInput({ name, label }: { name: string; label: string }) {
    return (
        <Input
            name={name}
            type="number"
            inputMode="numeric"
            min={0}
            max={100}
            aria-label={label}
            defaultValue=""
            className="w-full min-w-16"
        />
    );
}

/**
 * ネイティブの select を使う。行が20個並ぶ画面では、Radix の Select を
 * 人数ぶん置くとレンダリングが重くなり、タブレットで操作が遅れる。
 */
function NativeSelect({
    name,
    label,
    children,
}: {
    name: string;
    label: string;
    children: ReactNode;
}) {
    return (
        <select
            name={name}
            aria-label={label}
            defaultValue=""
            // 中身の幅で列を決めさせない。表が狭いときに矢印だけの
            // 大きさまで潰れ、何を選ぶ欄なのか分からなくなる。
            className="border-input bg-background focus-visible:ring-ring h-11 w-full min-w-28 rounded-md border px-2 text-base shadow-xs outline-none focus-visible:ring-[3px] md:text-sm"
        >
            {children}
        </select>
    );
}

BatchEntry.layout = {
    breadcrumbs: [
        { title: 'ダッシュボード', href: dashboard() },
        { title: '記録一覧', href: records.index() },
    ],
};
