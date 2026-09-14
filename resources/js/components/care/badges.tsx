import { Badge } from '@/components/ui/badge';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import {
    PROGRESS_STATUS,
    RECORD_STATUS,
    RISK_SEVERITY,
    riskSourceBadges,
} from '@/lib/care-presentation';
import { cn } from '@/lib/utils';
import type {
    ProgressStatus,
    RecordStatus,
    RiskSeverity,
    RiskSource,
} from '@/types/care';

/**
 * 業務バッジ。色とアイコンは lib/care-presentation.ts だけが決める。
 * このファイルは並べ方と読み上げの面倒を見る。
 *
 * 文字は font-semibold にしてある。小さな色つき文字は、細いと色そのものが
 * 読み取りにくくなる。職員は50〜60代が中心で、加齢でコントラスト感度が落ちる。
 */

/** バッジ共通の形。寸法はここでだけ決める。 */
const SHAPE = 'gap-1.5 font-semibold';

/**
 * 検出元のバッジ。この画面でいちばん重要な表示。
 *
 * ルールベースの指摘は、しきい値と記録の数値から決まる。同じ記録なら
 * 必ず同じ結果が出るし、なぜその結論になったかを数値で説明できる。
 *
 * LLMの指摘は、記述を読まないと分からない変化を拾ったものである。
 * 有用だが、同じ記録でも言い回しが変わりうるし、根拠の確認が要る。
 *
 * この2つを同じ見た目で並べると、職員はどちらも同じ重さで受け取ってしまう。
 * 全部をLLMに投げる実装との違いが、ここに出る（要件定義 7.1節）。
 *
 * 「両方で検出」は3つ目の色を作らず、2枚並べて返す。色を1つ増やすより、
 * 知っている2枚が同時に出るほうが取り違えが起きない。
 */
export function SourceBadge({ source }: { source: RiskSource }) {
    return (
        <>
            {riskSourceBadges(source).map((style) => {
                const Icon = style.icon;

                return (
                    <Tooltip key={style.label}>
                        <TooltipTrigger asChild>
                            <Badge
                                variant="outline"
                                className={cn(SHAPE, style.className)}
                            >
                                {Icon && (
                                    <Icon className="size-4" aria-hidden />
                                )}
                                {style.label}
                            </Badge>
                        </TooltipTrigger>
                        <TooltipContent className="max-w-72">
                            {style.help}
                        </TooltipContent>
                    </Tooltip>
                );
            })}
        </>
    );
}

/** リスクの重要度。文言は PHP の RiskSeverity enum が持つ。 */
export function SeverityBadge({
    severity,
    label,
}: {
    severity: RiskSeverity;
    label: string;
}) {
    const style = RISK_SEVERITY[severity];
    const Icon = style.icon;

    return (
        <Badge variant="outline" className={cn(SHAPE, style.className)}>
            {Icon && <Icon className="size-4" aria-hidden />}
            {label}
        </Badge>
    );
}

/** 記録の入力状況。文言は画面側の言い回しなので care-presentation が持つ。 */
export function RecordStatusBadge({ status }: { status: RecordStatus }) {
    const style = RECORD_STATUS[status];
    const Icon = style.icon;

    return (
        <Badge variant="outline" className={cn(SHAPE, style.className)}>
            {Icon && <Icon className="size-4" aria-hidden />}
            {style.label}
        </Badge>
    );
}

/** 目標の進捗評価。文言は PHP の ProgressStatus enum が持つ。 */
export function ProgressBadge({
    status,
    label,
}: {
    status: ProgressStatus;
    label: string;
}) {
    return (
        <Badge
            variant="outline"
            className={cn(SHAPE, PROGRESS_STATUS[status].className)}
        >
            {label}
        </Badge>
    );
}
