import { CircleAlert, FunctionSquare, Sparkles } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import type {
    ProgressStatus,
    RecordStatus,
    RiskSeverity,
    RiskSource,
} from '@/types/care';

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
 */
export function SourceBadge({ source, label }: { source: RiskSource; label: string }) {
    const style = {
        rule_based: {
            className:
                'border-sky-300 bg-sky-50 text-sky-900 dark:border-sky-900 dark:bg-sky-950 dark:text-sky-200',
            icon: FunctionSquare,
            help: '記録の数値としきい値から算出しています。同じ記録なら必ず同じ結果になります。',
        },
        llm_detected: {
            className:
                'border-violet-300 bg-violet-50 text-violet-900 dark:border-violet-900 dark:bg-violet-950 dark:text-violet-200',
            icon: Sparkles,
            help: 'AIが記述から読み取った内容です。根拠の記録をご確認のうえ、職員がご判断ください。',
        },
        both: {
            className:
                'border-emerald-300 bg-emerald-50 text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200',
            icon: FunctionSquare,
            help: '数値の判定と記述の両方から検出しています。',
        },
    }[source];

    const Icon = style.icon;

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <Badge variant="outline" className={cn('gap-1 font-medium', style.className)}>
                    <Icon className="size-3" aria-hidden />
                    {label}
                </Badge>
            </TooltipTrigger>
            <TooltipContent className="max-w-72">{style.help}</TooltipContent>
        </Tooltip>
    );
}

export function SeverityBadge({
    severity,
    label,
}: {
    severity: RiskSeverity;
    label: string;
}) {
    const className = {
        high: 'border-transparent bg-red-600 text-white dark:bg-red-700',
        medium:
            'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200',
        low: 'border-transparent bg-muted text-muted-foreground',
    }[severity];

    return (
        <Badge variant="outline" className={cn('font-medium', className)}>
            {severity === 'high' && <CircleAlert className="size-3" aria-hidden />}
            {label}
        </Badge>
    );
}

/**
 * 記録の入力状況。
 *
 * 「AI下書き」を確定前と別の状態にしている。AIが書いた文章を職員が
 * 一度も読んでいない記録が、確定済みに混ざってはいけないため。
 */
export function RecordStatusBadge({ status }: { status: RecordStatus }) {
    const style = {
        confirmed: {
            label: '確定済み',
            className:
                'border-emerald-300 bg-emerald-50 text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200',
        },
        ai_draft: {
            label: 'AI下書き・未確認',
            className:
                'border-violet-300 bg-violet-50 text-violet-900 dark:border-violet-900 dark:bg-violet-950 dark:text-violet-200',
        },
        draft: {
            label: '未確定',
            className:
                'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200',
        },
    }[status];

    return (
        <Badge variant="outline" className={cn('font-medium', style.className)}>
            {style.label}
        </Badge>
    );
}

/**
 * 進捗評価。
 *
 * 「判断できる材料が不足」を目立たせている。記録が足りないまま評価を
 * 書かせないことがこの機能の要点であり、その状態こそ職員が見るべきもの
 * だからである（要件定義 7.3節）。
 */
export function ProgressBadge({
    status,
    label,
}: {
    status: ProgressStatus;
    label: string;
}) {
    const className = {
        improving:
            'border-emerald-300 bg-emerald-50 text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200',
        unchanged: 'border-transparent bg-muted text-muted-foreground',
        declining:
            'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200',
        insufficient_data:
            'border-dashed border-foreground/40 bg-background text-foreground',
    }[status];

    return (
        <Badge variant="outline" className={cn('font-medium', className)}>
            {label}
        </Badge>
    );
}
