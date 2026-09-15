import { useState } from 'react';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { cn } from '@/lib/utils';

export type Choice = {
    value: string;
    /** ボタンに出す短い表記。 */
    label: string;
    /** 読み上げと確認に使う正式な表記。省略すると label を使う。 */
    fullLabel?: string;
};

/**
 * 択一のトグル。選んだものをもう一度押すと未選択へ戻る。
 *
 * 【なぜ select ではなくボタンを並べるか】
 * 一括入力の表は1人1行で並ぶ。select は「開く→選ぶ→閉じる」の3動作が要り、
 * 20名ぶんでは60動作になる。ボタンを並べれば1動作で終わる。
 * 選択肢が3つまでなら、横幅も表の中に収まる。
 *
 * 【もう一度押して消せることが要る】
 * 押し間違えたまま保存すると、実施していない入浴が記録として残る。
 * 空欄へ戻す道がないと、画面を読み込み直すしかなくなる。
 *
 * 【選択中はベタ塗りにする】
 * 既定の薄い面では、20行のうちどれを押したかが遠目に分からない。
 * ブランド色のベタ塗りは「選択中」を表す使い方（デザインガイド 2節）。
 */
export function ChoiceToggle({
    name,
    choices,
    ariaLabel,
    className,
}: {
    /** 送信名。未選択のときは空文字を送る。 */
    name: string;
    choices: Choice[];
    ariaLabel: string;
    className?: string;
}) {
    const [value, setValue] = useState('');

    return (
        <>
            {/*
             * 値は hidden で送る。ToggleGroup はボタンであって入力欄ではなく、
             * そのままでは form に乗らない。
             */}
            <input type="hidden" name={name} value={value} />

            <ToggleGroup
                type="single"
                variant="outline"
                value={value}
                onValueChange={setValue}
                aria-label={ariaLabel}
                className={cn('w-full', className)}
            >
                {choices.map((choice) => (
                    <ToggleGroupItem
                        key={choice.value}
                        value={choice.value}
                        aria-label={choice.fullLabel ?? choice.label}
                        className="data-[state=on]:border-primary data-[state=on]:bg-primary data-[state=on]:text-primary-foreground h-11 flex-1 px-2 text-sm font-medium"
                    >
                        {choice.label}
                    </ToggleGroupItem>
                ))}
            </ToggleGroup>
        </>
    );
}
