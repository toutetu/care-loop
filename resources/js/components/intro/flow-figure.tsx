import {
    ArrowDown,
    ArrowRight,
    ClipboardList,
    FileText,
    HeartHandshake,
    Mic,
    RefreshCw,
    Sparkles,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

/**
 * 「1回の入力から3つの出口」と「記録の循環」を描いた図。
 *
 * 画像ではなく HTML で描いている。文字が選択でき、暗モードでも
 * そのまま読め、文言を直すときに画像を作り直さなくて済む。
 */
const OUTPUTS: {
    icon: LucideIcon;
    title: string;
    body: string;
}[] = [
    {
        icon: FileText,
        title: '記録用',
        body: '法定文書としての文体。専門用語で正確に',
    },
    {
        icon: HeartHandshake,
        title: 'ご家族向け',
        body: '敬語で平易に。安心して読める文章に',
    },
    {
        icon: ClipboardList,
        title: '申し送り用',
        body: '簡潔に。次の担当者が取る行動を明示',
    },
];

const LOOP = ['記録', 'AI変換', '現場・ご家族', '次のケア'];

export function FlowFigure() {
    return (
        <figure className="bg-card shadow-card rounded-2xl border p-5 sm:p-8">
            <div className="grid items-center gap-4 md:grid-cols-[1fr_auto_1fr_auto_1.4fr]">
                {/* 入口 */}
                <div className="bg-primary/10 flex items-center gap-3 rounded-xl p-4">
                    <span
                        className="bg-primary text-primary-foreground flex size-11 shrink-0 items-center justify-center rounded-lg"
                        aria-hidden
                    >
                        <Mic className="size-6" />
                    </span>
                    <div>
                        <p className="font-bold">職員が話す</p>
                        <p className="text-muted-foreground text-sm">
                            スマートフォンに 1 回
                        </p>
                    </div>
                </div>

                <Arrow />

                {/* AI */}
                <div className="border-ai-line bg-ai-soft flex items-center gap-3 rounded-xl border p-4">
                    <span
                        className="text-ai-ink flex size-11 shrink-0 items-center justify-center"
                        aria-hidden
                    >
                        <Sparkles className="size-7" />
                    </span>
                    <div>
                        <p className="text-ai-ink font-bold">AI が書き分ける</p>
                        {/* 3列が横に並ぶ幅では、ここが長いと1文字だけ
                            次の行へこぼれる。短い言い切りにしてある。 */}
                        <p className="text-ai-ink/80 text-sm">
                            原文はそのまま残す
                        </p>
                    </div>
                </div>

                <Arrow />

                {/* 出口 */}
                <ol className="grid gap-2">
                    {OUTPUTS.map((output, index) => (
                        <li
                            key={output.title}
                            className="flex items-center gap-3 rounded-lg border px-3 py-2"
                        >
                            <span
                                className="bg-muted text-foreground flex size-8 shrink-0 items-center justify-center rounded-md"
                                aria-hidden
                            >
                                <output.icon className="size-4" />
                            </span>
                            <div className="min-w-0">
                                <p className="text-sm font-bold">
                                    <span className="text-muted-foreground mr-1 font-mono text-xs">
                                        {index + 1}
                                    </span>
                                    {output.title}
                                </p>
                                <p className="text-muted-foreground text-xs">
                                    {output.body}
                                </p>
                            </div>
                        </li>
                    ))}
                </ol>
            </div>

            {/* 循環 */}
            <div className="mt-6 border-t pt-5">
                <p className="text-muted-foreground mb-3 flex items-center gap-2 text-sm">
                    <RefreshCw className="size-4" aria-hidden />
                    蓄積した記録は、目標の進捗要約・リスク兆候の抽出・連絡帳へ集約され、次のケアへ戻る
                </p>
                <ol className="flex flex-wrap items-center gap-2 text-sm font-bold">
                    {LOOP.map((step, index) => (
                        <li key={step} className="flex items-center gap-2">
                            <span className="bg-muted rounded-full px-3 py-1">
                                {step}
                            </span>
                            <ArrowRight
                                className="text-muted-foreground size-4"
                                aria-hidden
                            />
                            {index === LOOP.length - 1 && (
                                <span className="text-primary flex items-center gap-1">
                                    <RefreshCw className="size-4" aria-hidden />
                                    Loop
                                </span>
                            )}
                        </li>
                    ))}
                </ol>
            </div>
            <figcaption className="sr-only">
                職員が1回話した内容を、AIが記録用・ご家族向け・申し送り用の3つに書き分け、蓄積した記録が次のケアへ循環する図
            </figcaption>
        </figure>
    );
}

function Arrow() {
    return (
        <div
            className="text-muted-foreground flex items-center justify-center"
            aria-hidden
        >
            <ArrowDown className="size-5 md:hidden" />
            <ArrowRight className="hidden size-5 md:block" />
        </div>
    );
}
