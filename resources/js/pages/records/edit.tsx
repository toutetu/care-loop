import { Form, Head, Link } from '@inertiajs/react';
import {
    Info,
    Lock,
    MessageSquareWarning,
    Mic,
    MicOff,
    Printer,
    Sparkles,
    UserPen,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import LlmActionController from '@/actions/App/Http/Controllers/LlmActionController';
import ServiceRecordController from '@/actions/App/Http/Controllers/ServiceRecordController';
import { RecordStatusBadge } from '@/components/care/badges';
import { Section } from '@/components/care/section';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { useSpeechRecognition } from '@/hooks/use-speech-recognition';
import { dashboard } from '@/routes';
import records from '@/routes/records';
import residentRoutes from '@/routes/residents';

type RecordProps = {
    id: number;
    recorder: string | null;
    residentId: number;
    residentName: string;
    careLevel: string | null;
    date: string;
    arrivalTime: string | null;
    departureTime: string | null;
    attendanceStatus: string;
    absenceReason: string | null;
    bathingPerformed: boolean | null;
    totalWaterMl: number | null;
    rawNote: string | null;
    recordText: string | null;
    familyText: string | null;
    handoverNote: string | null;
    recordTextEditedByHuman: boolean;
    familyTextEditedByHuman: boolean;
    confirmedAt: string | null;
    hasAiDraft: boolean;
    vital: {
        temperature: number | null;
        systolic_bp: number | null;
        diastolic_bp: number | null;
        pulse: number | null;
        spo2: number | null;
    };
    lunch: {
        staple_rate: number | null;
        side_rate: number | null;
        meal_form: string | null;
        choking: boolean;
    };
};

type Props = {
    record: RecordProps;
    verbalContacts: { id: number; topic: string; reason: string | null }[];
    mealForms: string[];
    /**
     * 書き換えられるか。
     *
     * 閲覧は同じ事業所の職員なら誰でもできる。リスク指摘の根拠になった記録を
     * 確認できないと、AIの出力を職員が検証するという前提が成り立たないため。
     * 書き換えは記録した本人か管理者以上に限る。
     */
    canEdit: boolean;
};

/**
 * サービス提供記録の入力。
 *
 * 画面はスマートフォンの縦持ちを基準に組んでいる。記録はフロアで書くもので、
 * 事務所のパソコンまで戻ると、思い出しながら書くことになる。
 */
export default function RecordEdit({ record, verbalContacts, mealForms, canEdit }: Props) {
    const [rawNote, setRawNote] = useState(record.rawNote ?? '');
    const [mealForm, setMealForm] = useState(record.lunch.meal_form ?? '');

    // 別の記録へ移ったら入力中の値を捨てる。
    // Inertia は同じ画面のあいだコンポーネントを作り直さないため、
    // これがないと前のご利用者の入力が残ったまま次の記録に表示される。
    const [shownRecordId, setShownRecordId] = useState(record.id);

    if (shownRecordId !== record.id) {
        setShownRecordId(record.id);
        setRawNote(record.rawNote ?? '');
        setMealForm(record.lunch.meal_form ?? '');
    }

    /**
     * 記録一覧の「確認して確定」から来たときは、読むべき文章まで移動する。
     *
     * 確定のチェック欄は画面下部に貼り付いていて常に見えているが、
     * 先に読ませたいのは生成された文章のほうである。
     * チェック欄へ直接飛ばすと、読まずにチェックできてしまう。
     */
    useEffect(() => {
        if (window.location.hash !== '#confirm') {
            return;
        }

        document
            .getElementById('record-texts')
            ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, [record.id]);

    const { supported, listening, start, stop } = useSpeechRecognition((text) =>
        // 認識結果は追記する。上書きすると、それまで話した内容が消える。
        setRawNote((current) => (current === '' ? text : `${current}${text}`)),
    );

    return (
        <>
            <Head title={`${record.residentName} 様 ${record.date}`} />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">
                            <Link
                                href={residentRoutes.show(record.residentId)}
                                className="hover:underline"
                            >
                                {record.residentName} 様
                            </Link>
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {record.date}
                            {record.careLevel && ` ／ ${record.careLevel}`}
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <RecordStatusBadge
                            status={
                                record.hasAiDraft
                                    ? 'ai_draft'
                                    : record.confirmedAt
                                      ? 'confirmed'
                                      : 'draft'
                            }
                        />
                        <Button variant="outline" size="sm" asChild>
                            <a
                                href={records.familyReport(record.id).url}
                                target="_blank"
                                rel="noreferrer"
                            >
                                <Printer className="size-4" aria-hidden />
                                連絡帳
                            </a>
                        </Button>
                    </div>
                </div>

                {/* AIが書いた文章が未確認のまま残っている記録では、何をすれば
                    確定するのかをここで伝える。バッジだけでは、次にどう操作すれば
                    よいのかが分からない。 */}
                {record.hasAiDraft && canEdit && (
                    <div className="rounded-md border border-violet-300 bg-violet-50 px-3 py-2 dark:border-violet-900 dark:bg-violet-950">
                        <p className="flex items-start gap-2 text-sm font-medium">
                            <Sparkles className="mt-0.5 size-4 shrink-0" aria-hidden />
                            AIが生成した下書きが未確認です
                        </p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            下の「記録の文章」をお読みいただき、必要なら直してください。
                            そのうえで最下部の「内容を確認したので確定する」にチェックを入れ、
                            保存すると確定します。
                        </p>
                        <Button
                            type="button"
                            variant="secondary"
                            size="sm"
                            className="mt-2"
                            onClick={() =>
                                document
                                    .getElementById('record-texts')
                                    ?.scrollIntoView({ behavior: 'smooth', block: 'start' })
                            }
                        >
                            記録の文章を確認する
                        </Button>
                    </div>
                )}

                {/* 書き換えられない記録は、そのことを最初に伝える。
                    保存できないと分かるのが最後では、入力した時間が無駄になる。 */}
                {!canEdit && (
                    <p className="flex items-start gap-2 rounded-md border bg-muted px-3 py-2 text-sm">
                        <Lock className="mt-0.5 size-4 shrink-0" aria-hidden />
                        この記録は
                        {record.recorder ? `${record.recorder} さん` : '他の職員'}
                        が作成したため、閲覧のみです。訂正が必要な場合は、記録者か管理者にご依頼ください。
                    </p>
                )}

                {/* --- 音声入力とAI変換 --- */}
                <Section
                    title="音声入力"
                    description="お話しになった内容をそのまま入力してください。整えるのはAIが行います。"
                >
                    <div className="space-y-3">
                        <div className="relative">
                            <textarea
                                value={rawNote}
                                onChange={(event) => setRawNote(event.target.value)}
                                rows={5}
                                form="record-form"
                                name="raw_note"
                                readOnly={!canEdit}
                                placeholder="例：午前中は体操に参加されて えーっと 昼食のときに少しむせこみがあって 水分は1000mlくらい"
                                className="w-full rounded-md border border-input bg-background px-3 py-2 text-base shadow-xs outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            />
                            {/* このボタンは、対応ブラウザでのみ表示される（要件定義 7.8節 ②）。
                                iOSではキーボードのマイクを使う想定なので出さない。 */}
                            {supported && canEdit && (
                                <Button
                                    type="button"
                                    variant={listening ? 'destructive' : 'outline'}
                                    size="sm"
                                    onClick={listening ? stop : start}
                                    className="absolute right-2 bottom-2"
                                >
                                    {listening ? (
                                        <>
                                            <MicOff className="size-4" aria-hidden />
                                            停止
                                        </>
                                    ) : (
                                        <>
                                            <Mic className="size-4" aria-hidden />
                                            音声入力
                                        </>
                                    )}
                                </Button>
                            )}
                        </div>

                        <p className="flex items-start gap-2 text-xs text-muted-foreground">
                            <Info className="mt-0.5 size-3.5 shrink-0" aria-hidden />
                            この原文は書き換えません。AIが何を変えたのかを後から確認できるよう、そのまま保存します。
                            お名前などはAIへ送る前に伏せ字へ置き換えています。
                        </p>

                        {canEdit && (
                            <Form
                                {...LlmActionController.transformVoice.form(record.id)}
                                options={{ preserveScroll: true }}
                            >
                                {({ processing }) => (
                                    <>
                                        {/* 押した時点の原文を一緒に送る。保存を忘れたまま
                                            押しても、画面に見えている内容で変換される。 */}
                                        <input type="hidden" name="raw_note" value={rawNote} />
                                        <Button
                                            type="submit"
                                            disabled={processing || rawNote.trim() === ''}
                                        >
                                            {processing ? (
                                                <Spinner className="size-4" />
                                            ) : (
                                                <Sparkles className="size-4" aria-hidden />
                                            )}
                                            記録・ご家族向け・申し送りに変換
                                        </Button>
                                    </>
                                )}
                            </Form>
                        )}
                    </div>
                </Section>

                {verbalContacts.length > 0 && (
                    <Section
                        title="お迎えの際にお伝えする事項"
                        description="連絡帳にも記載されますが、口頭でも必ずお伝えしてください。"
                    >
                        <ul className="space-y-2">
                            {verbalContacts.map((task) => (
                                <li
                                    key={task.id}
                                    className="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm dark:border-amber-900 dark:bg-amber-950"
                                >
                                    <p className="flex items-center gap-2 font-medium">
                                        <MessageSquareWarning className="size-4 shrink-0" aria-hidden />
                                        {task.topic}
                                    </p>
                                    {task.reason && (
                                        <p className="mt-1 text-muted-foreground">{task.reason}</p>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </Section>
                )}

                <Form
                    id="record-form"
                    {...ServiceRecordController.update.form(record.id)}
                    options={{ preserveScroll: true }}
                    className="flex flex-col gap-4"
                >
                    {({ processing, errors }) => (
                        // fieldset ごと無効にする。個々の入力に disabled を付けて回ると
                        // 必ずどれかを付け忘れる。
                        <fieldset disabled={!canEdit} className="flex flex-col gap-4">
                            {/* --- 利用状況 --- */}
                            <Section title="利用状況">
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="grid gap-2">
                                        <Label htmlFor="arrival_time">到着時刻</Label>
                                        <Input
                                            id="arrival_time"
                                            name="arrival_time"
                                            type="time"
                                            defaultValue={record.arrivalTime ?? ''}
                                        />
                                        <InputError message={errors.arrival_time} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="departure_time">帰宅時刻</Label>
                                        <Input
                                            id="departure_time"
                                            name="departure_time"
                                            type="time"
                                            defaultValue={record.departureTime ?? ''}
                                        />
                                        <InputError message={errors.departure_time} />
                                    </div>
                                </div>

                                <input
                                    type="hidden"
                                    name="attendance_status"
                                    value={record.attendanceStatus}
                                />

                                <div className="mt-4 flex items-center gap-2">
                                    {/* 音声から抽出された値が入ることがある。
                                        サーバーの値が変わったら作り直す（TextBlock と同じ理由） */}
                                    <Checkbox
                                        key={String(record.bathingPerformed)}
                                        id="bathing_performed"
                                        name="bathing_performed"
                                        value="1"
                                        defaultChecked={record.bathingPerformed === true}
                                    />
                                    <Label htmlFor="bathing_performed">入浴された</Label>
                                </div>
                            </Section>

                            {/* --- バイタル --- */}
                            <Section
                                title="バイタル"
                                description="測っていない項目は空欄のままにしてください。0と書くと、測って0だったという記録になります。"
                            >
                                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                    <div className="grid gap-2">
                                        <Label htmlFor="temperature">体温（℃）</Label>
                                        <Input
                                            id="temperature"
                                            name="vital[temperature]"
                                            type="number"
                                            step="0.1"
                                            inputMode="decimal"
                                            defaultValue={record.vital.temperature ?? ''}
                                        />
                                        <InputError message={errors['vital.temperature']} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="systolic_bp">収縮期血圧</Label>
                                        <Input
                                            id="systolic_bp"
                                            name="vital[systolic_bp]"
                                            type="number"
                                            inputMode="numeric"
                                            defaultValue={record.vital.systolic_bp ?? ''}
                                        />
                                        <InputError message={errors['vital.systolic_bp']} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="diastolic_bp">拡張期血圧</Label>
                                        <Input
                                            id="diastolic_bp"
                                            name="vital[diastolic_bp]"
                                            type="number"
                                            inputMode="numeric"
                                            defaultValue={record.vital.diastolic_bp ?? ''}
                                        />
                                        <InputError message={errors['vital.diastolic_bp']} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="pulse">脈拍（回/分）</Label>
                                        <Input
                                            id="pulse"
                                            name="vital[pulse]"
                                            type="number"
                                            inputMode="numeric"
                                            defaultValue={record.vital.pulse ?? ''}
                                        />
                                        <InputError message={errors['vital.pulse']} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="spo2">SpO2（%）</Label>
                                        <Input
                                            id="spo2"
                                            name="vital[spo2]"
                                            type="number"
                                            inputMode="numeric"
                                            defaultValue={record.vital.spo2 ?? ''}
                                        />
                                        <InputError message={errors['vital.spo2']} />
                                    </div>
                                </div>
                            </Section>

                            {/* --- 食事・水分 --- */}
                            <Section title="昼食・水分">
                                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                    <div className="grid gap-2">
                                        <Label htmlFor="staple_rate">主食（%）</Label>
                                        <Input
                                            key={String(record.lunch.staple_rate)}
                                            id="staple_rate"
                                            name="lunch[staple_rate]"
                                            type="number"
                                            inputMode="numeric"
                                            defaultValue={record.lunch.staple_rate ?? ''}
                                        />
                                        <InputError message={errors['lunch.staple_rate']} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="side_rate">副菜（%）</Label>
                                        <Input
                                            key={String(record.lunch.side_rate)}
                                            id="side_rate"
                                            name="lunch[side_rate]"
                                            type="number"
                                            inputMode="numeric"
                                            defaultValue={record.lunch.side_rate ?? ''}
                                        />
                                        <InputError message={errors['lunch.side_rate']} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="meal_form">食形態</Label>
                                        <Select value={mealForm} onValueChange={setMealForm}>
                                            <SelectTrigger id="meal_form">
                                                <SelectValue placeholder="選択してください" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {mealForms.map((form) => (
                                                    <SelectItem key={form} value={form}>
                                                        {form}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <input
                                            type="hidden"
                                            name="lunch[meal_form]"
                                            value={mealForm}
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="total_water_ml">水分合計（ml）</Label>
                                        <Input
                                            key={String(record.totalWaterMl)}
                                            id="total_water_ml"
                                            name="total_water_ml"
                                            type="number"
                                            inputMode="numeric"
                                            defaultValue={record.totalWaterMl ?? ''}
                                        />
                                        <InputError message={errors.total_water_ml} />
                                    </div>
                                </div>

                                <div className="mt-4 flex items-center gap-2">
                                    <Checkbox
                                        id="choking"
                                        name="lunch[choking]"
                                        value="1"
                                        defaultChecked={record.lunch.choking}
                                    />
                                    <Label htmlFor="choking">
                                        むせ込みがあった
                                        <span className="ml-1 text-xs font-normal text-muted-foreground">
                                            （誤嚥リスクの判定に使います）
                                        </span>
                                    </Label>
                                </div>
                            </Section>

                            {/* --- 3つの文章 --- */}
                            {/* 記録一覧の「確認して確定」から、ここへ案内する */}
                            <div id="record-texts" className="scroll-mt-4">
                            <Section
                                title="記録の文章"
                                description="AIが生成した下書きです。内容をご確認のうえ、必要に応じて直してください。"
                            >
                                <div className="space-y-4">
                                    <TextBlock
                                        id="record_text"
                                        label="記録用"
                                        hint="サービス提供記録に残る文章です。"
                                        defaultValue={record.recordText}
                                        edited={record.recordTextEditedByHuman}
                                    />
                                    <TextBlock
                                        id="family_text"
                                        label="ご家族向け"
                                        hint="連絡帳に載る文章です。"
                                        defaultValue={record.familyText}
                                        edited={record.familyTextEditedByHuman}
                                    />
                                    <TextBlock
                                        id="handover_note"
                                        label="申し送り"
                                        hint="次の担当者が取るべき行動があるときだけ書きます。"
                                        defaultValue={record.handoverNote}
                                        rows={2}
                                    />
                                </div>
                            </Section>
                            </div>

                            {/* --- 保存 --- */}
                            {canEdit && (
                                <div className="sticky bottom-0 flex flex-wrap items-center justify-between gap-3 rounded-lg border bg-background/95 px-4 py-3 backdrop-blur">
                                    <label className="flex items-center gap-2 text-sm">
                                        {/* AIが文章を書き直すと確定は外れる。チェックの状態も
                                            必ず作り直す。外れたはずのチェックが入ったまま
                                            残ると、職員が読んでいないAIの下書きが
                                            そのまま確定できてしまう。 */}
                                        <Checkbox
                                            key={String(record.confirmedAt)}
                                            name="confirm"
                                            value="1"
                                            defaultChecked={record.confirmedAt !== null}
                                            disabled={record.confirmedAt !== null}
                                        />
                                        {record.confirmedAt !== null ? (
                                            <span className="text-muted-foreground">
                                                {record.confirmedAt} に確定済み
                                            </span>
                                        ) : (
                                            <span>内容を確認したので確定する</span>
                                        )}
                                    </label>

                                    <Button type="submit" disabled={processing}>
                                        {processing && <Spinner className="size-4" />}
                                        保存
                                    </Button>
                                </div>
                            )}
                        </fieldset>
                    )}
                </Form>
            </div>
        </>
    );
}

/**
 * 生成された文章ひとつ分。
 *
 * 人が手を入れた文章には印を付ける。どこまでがAIの出力で、どこからが
 * 職員の判断なのかを後から追えないと、法定文書として説明できない。
 */
function TextBlock({
    id,
    label,
    hint,
    defaultValue,
    edited = false,
    rows = 4,
}: {
    id: string;
    label: string;
    hint: string;
    defaultValue: string | null;
    edited?: boolean;
    rows?: number;
}) {
    return (
        <div className="grid gap-2">
            <div className="flex flex-wrap items-center gap-2">
                <Label htmlFor={id}>{label}</Label>
                {edited && (
                    <Badge variant="outline" className="gap-1">
                        <UserPen className="size-3" aria-hidden />
                        職員が修正
                    </Badge>
                )}
                <span className="text-xs text-muted-foreground">{hint}</span>
            </div>
            {/* サーバーの値が変わったら作り直す。
                非制御の入力は再描画してもDOMの値が残るため、AIが書き直した文章が
                画面に出ないままになる。key を値に結びつけることで、
                サーバー側が変わったときだけ差し替わる。職員が手で書き換えた内容は、
                サーバーの値が変わっていない限り消えない。 */}
            <textarea
                key={defaultValue ?? ''}
                id={id}
                name={id}
                rows={rows}
                defaultValue={defaultValue ?? ''}
                className="w-full rounded-md border border-input bg-background px-3 py-2 text-base shadow-xs outline-none focus-visible:ring-2 focus-visible:ring-ring"
            />
        </div>
    );
}

RecordEdit.layout = {
    breadcrumbs: [
        { title: 'ダッシュボード', href: dashboard() },
        { title: '利用者一覧', href: residentRoutes.index() },
    ],
};
