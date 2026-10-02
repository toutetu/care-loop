import { Form, Link } from '@inertiajs/react';
import { Check, ChevronRight, Mic, MicOff, NotebookPen } from 'lucide-react';
import { useState } from 'react';
import RecordNoteController from '@/actions/App/Http/Controllers/RecordNoteController';
import { RecordStatusBadge } from '@/components/care/badges';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { useSpeechRecognition } from '@/hooks/use-speech-recognition';
import { cn } from '@/lib/utils';
import records from '@/routes/records';
import type { RecordStatus } from '@/types/care';

export type QuickVoiceRow = {
    recordId: number;
    name: string;
    status: RecordStatus;
    notesCount: number;
    canEdit: boolean;
};

/**
 * スマートフォンの記録一覧。お名前を押すと、その場で録音が始まる。
 *
 * 【タップ3回で残す】
 * 以前は 一覧 → 記録を開く → 音声入力の欄までスクロール → マイク → 停止 → 確定
 * と進む必要があった。介助の合間に片手で残すには多すぎて、結局あとで
 * 思い出しながら書くことになる。ここでは「お名前 → 停止 → 確定」で済ませる。
 *
 * 【確定の前に読ませるのは変えない】
 * 話し終えたら一度止め、聞き違いを直してから確定する。確定した原文は
 * 書き換えられず、AIの書き直しの材料になるからである（RecordNoteController）。
 * 手数を減らすために、この読み直しを省くことはしない。
 *
 * 【AIの書き直しはここで行わない】
 * 1日の中で何度もメモを足していき、まとめて書き直すほうが文章が整う。
 * 書き直しやバイタルの入力は、右端の矢印から記録を開いて行う。
 */
export function QuickVoiceList({ rows }: { rows: QuickVoiceRow[] }) {
    const [target, setTarget] = useState<QuickVoiceRow | null>(null);
    const [text, setText] = useState('');
    const [usedVoice, setUsedVoice] = useState(false);

    const { supported, listening, start, stop } = useSpeechRecognition(
        (heard) => {
            // 認識結果は追記する。上書きすると、それまで話した内容が消える。
            setText((current) =>
                current === '' ? heard : `${current}${heard}`,
            );
            setUsedVoice(true);
        },
    );

    const open = (row: QuickVoiceRow) => {
        setTarget(row);
        setText('');
        setUsedVoice(false);

        // 押した操作の中で録音を始める。画面が開いてから始めると、
        // ブラウザによってはマイクの利用が許されない。
        if (supported) {
            start();
        }
    };

    const close = () => {
        // 確定していないメモを、閉じただけで黙って捨てない
        if (
            text.trim() !== '' &&
            !window.confirm('確定していないメモがあります。破棄しますか？')
        ) {
            return;
        }

        stop();
        setTarget(null);
    };

    const hasText = text.trim() !== '';

    return (
        <>
            <p className="text-muted-foreground text-sm">
                {supported
                    ? 'お名前を押すと、すぐに録音が始まります。'
                    : 'お名前を押すと、メモの欄が開きます。キーボードのマイクで話せます。'}
            </p>

            <ul className="divide-y rounded-lg border">
                {rows.map((row) => (
                    <li key={row.recordId} className="flex items-stretch">
                        {row.canEdit ? (
                            <button
                                type="button"
                                onClick={() => open(row)}
                                className="focus-visible:ring-ring hover:bg-accent flex min-h-14 min-w-0 flex-1 items-center gap-2 px-3 text-left outline-none focus-visible:ring-[3px] focus-visible:ring-inset"
                            >
                                <RowBody row={row} />
                            </button>
                        ) : (
                            <Link
                                href={records.edit(row.recordId)}
                                className="hover:bg-accent flex min-h-14 min-w-0 flex-1 items-center gap-2 px-3"
                            >
                                <RowBody row={row} />
                            </Link>
                        )}

                        {/* バイタルやAIの書き直しは記録を開いて行う。
                            名前の押し間違いと区別できるよう、境目に線を引く。 */}
                        <Link
                            href={records.edit(row.recordId)}
                            aria-label={`${row.name} 様の記録を開く`}
                            className="text-muted-foreground hover:bg-accent focus-visible:ring-ring flex w-14 shrink-0 items-center justify-center border-l outline-none focus-visible:ring-[3px] focus-visible:ring-inset"
                        >
                            <ChevronRight className="size-5" aria-hidden />
                        </Link>
                    </li>
                ))}
            </ul>

            <Sheet
                open={target !== null}
                onOpenChange={(next) => {
                    if (!next) {
                        close();
                    }
                }}
            >
                <SheetContent
                    side="bottom"
                    className="rounded-t-xl pb-[env(safe-area-inset-bottom)]"
                    // 録音しているあいだは入力欄に触れない。キーボードが開くと
                    // 画面の半分が隠れ、聞き取った文が見えなくなる。
                    // マイクが使えない端末では、すぐキーボードで話せるよう欄に入る。
                    onOpenAutoFocus={(event) => {
                        if (supported) {
                            event.preventDefault();
                        }
                    }}
                >
                    {target && (
                        <Form
                            {...RecordNoteController.store.form(
                                target.recordId,
                            )}
                            options={{ preserveScroll: true }}
                            onSuccess={() => {
                                setText('');
                                setUsedVoice(false);
                                setTarget(null);
                            }}
                            className="flex flex-col gap-3"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <SheetHeader className="pb-0">
                                        <SheetTitle className="text-lg">
                                            {target.name} 様のメモ
                                        </SheetTitle>
                                        <SheetDescription
                                            aria-live="polite"
                                            className={cn(
                                                listening &&
                                                    'text-foreground font-medium',
                                            )}
                                        >
                                            {listening
                                                ? '聞き取っています。話し終えたら「停止」を押してください。'
                                                : hasText
                                                  ? '聞き違いがないか読み直して、「確定」を押してください。'
                                                  : '話した内容は「確定」を押すまで保存されません。'}
                                        </SheetDescription>
                                    </SheetHeader>

                                    <div className="space-y-1 px-4">
                                        <textarea
                                            value={text}
                                            onChange={(event) => {
                                                setText(event.target.value);

                                                // 全部消したら、次に入るのは別の内容
                                                if (event.target.value === '') {
                                                    setUsedVoice(false);
                                                }
                                            }}
                                            rows={4}
                                            name="body"
                                            aria-label={`${target.name} 様のメモ`}
                                            placeholder="例：昼食のときに少しむせこみがあった"
                                            className="border-input bg-background focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-base shadow-xs outline-none focus-visible:ring-2"
                                        />
                                        <input
                                            type="hidden"
                                            name="input_method"
                                            value={
                                                usedVoice ? 'voice' : 'keyboard'
                                            }
                                        />
                                        <InputError message={errors.body} />
                                    </div>

                                    <div className="flex gap-2 px-4 pb-4">
                                        {supported && (
                                            <Button
                                                type="button"
                                                size="lg"
                                                variant={
                                                    listening
                                                        ? 'destructive'
                                                        : 'outline'
                                                }
                                                onClick={
                                                    listening ? stop : start
                                                }
                                                // 確定を送っているあいだに話し始めると、
                                                // 確定のあとで欄を空にするときに消えてしまう
                                                disabled={processing}
                                                className="min-h-12 flex-1"
                                            >
                                                {listening ? (
                                                    <>
                                                        <MicOff
                                                            className="size-5"
                                                            aria-hidden
                                                        />
                                                        停止
                                                    </>
                                                ) : (
                                                    <>
                                                        <Mic
                                                            className="size-5"
                                                            aria-hidden
                                                        />
                                                        続けて話す
                                                    </>
                                                )}
                                            </Button>
                                        )}
                                        {/* 聞き取りの途中では押させない。停止したあとに
                                            届いた言葉が、確定したあとの空の欄に入ってしまう。 */}
                                        <Button
                                            type="submit"
                                            size="lg"
                                            pending={processing}
                                            disabled={
                                                processing ||
                                                listening ||
                                                !hasText
                                            }
                                            className="min-h-12 flex-1"
                                        >
                                            {!processing && (
                                                <Check
                                                    className="size-5"
                                                    aria-hidden
                                                />
                                            )}
                                            {processing
                                                ? '確定しています…'
                                                : '確定'}
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    )}
                </SheetContent>
            </Sheet>
        </>
    );
}

/** 1行の中身。お名前を大きく、状態は右に小さく寄せる。 */
function RowBody({ row }: { row: QuickVoiceRow }) {
    return (
        <>
            <span className="min-w-0 flex-1 truncate text-base font-semibold">
                {row.name} 様
            </span>
            {/* もう話して残したかが分かれば、同じことを二度残さずに済む */}
            {row.notesCount > 0 && (
                <span className="text-muted-foreground flex shrink-0 items-center gap-1 text-xs tabular-nums">
                    <NotebookPen className="size-3.5" aria-hidden />
                    {row.notesCount}
                    <span className="sr-only">件のメモ</span>
                </span>
            )}
            <RecordStatusBadge status={row.status} />
        </>
    );
}
