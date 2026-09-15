import { ArrowDown, ArrowRight } from 'lucide-react';
import type { ReactNode } from 'react';

export type ChallengeSolution = {
    challenge: ReactNode;
    solution: ReactNode;
};

/**
 * 「課題 → 工夫」を1行ずつ対にして並べる。
 *
 * 【なぜ表ではなく対にするか】
 * 課題の列と工夫の列を別々の表にすると、どの工夫がどの課題に応えたのかを
 * 読む人が突き合わせることになる。1行に1対で置けば、左を読んで右を読む
 * だけで済む。スマートフォンでは上下に積み、矢印を下向きにする。
 *
 * 左右の見出し（課題／工夫）は呼び出し側が決める。UI の節では
 * 「課題／解決」と言い分けたいため。
 */
export function ChallengeSolutionList({
    items,
    challengeLabel = '課題',
    solutionLabel = '工夫',
}: {
    items: ChallengeSolution[];
    challengeLabel?: string;
    solutionLabel?: string;
}) {
    return (
        <ol className="space-y-4">
            {items.map((item, index) => (
                <li
                    key={index}
                    className="bg-card shadow-card grid overflow-hidden rounded-xl border md:grid-cols-[1fr_auto_1fr]"
                >
                    <div className="bg-muted/50 p-5">
                        <p className="text-warning-ink text-xs font-bold tracking-wide">
                            {challengeLabel} {index + 1}
                        </p>
                        <div className="mt-1.5 text-sm leading-relaxed">
                            {item.challenge}
                        </div>
                    </div>

                    <div
                        className="text-muted-foreground flex items-center justify-center px-2 py-1 md:py-5"
                        aria-hidden
                    >
                        <ArrowDown className="size-5 md:hidden" />
                        <ArrowRight className="hidden size-5 md:block" />
                    </div>

                    <div className="p-5">
                        <p className="text-success-ink text-xs font-bold tracking-wide">
                            {solutionLabel}
                        </p>
                        <div className="mt-1.5 text-sm leading-relaxed">
                            {item.solution}
                        </div>
                    </div>
                </li>
            ))}
        </ol>
    );
}
