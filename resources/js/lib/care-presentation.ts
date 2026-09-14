import { CircleAlert, FunctionSquare, Sparkles } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type {
    ProgressStatus,
    RecordStatus,
    RiskSeverity,
    RiskSource,
} from '@/types/care';

/**
 * 業務上の状態を、画面上の見た目へ変換する対応表。
 *
 * 【なぜ1ファイルに集めるか】
 * 以前は同じ色の組が badges.tsx・dashboard.tsx・residents/show.tsx・
 * records/edit.tsx に別々に書かれていた。「注意の色を変える」が
 * 4ファイル横断の作業になり、片方だけ直って食い違う。
 * 色を決めるのはここだけにし、画面は結果を読むだけにする。
 *
 * 【色の意味は2軸】
 * 軸1（danger / warning / success / 無彩色）＝ 職員が手を動かす必要があるか。
 * 軸2（ai / deterministic）＝ その情報を誰が出したか。
 * 軸をまたいで同じ色を使わない。以前は emerald が「改善」「確定済み」と
 * 「ルールとAIの両方が検出」の三つを兼ねていた。両方で検出はいちばん
 * 確からしいリスクであり、良い知らせと同じ色で出てはいけない。
 *
 * 【文言はどちらが持つか】
 * 重要度と進捗評価の文言は PHP の enum が持っており、画面はそれを受け取る。
 * ここで同じ文字列を持つと二重管理になるため、色だけを定義する。
 * 検出元と記録の状態は画面側の言い回しなので、ここが正本になる。
 *
 * 色の実体は resources/css/app.css の semantic token にある。
 * 明暗どちらのモードでも文字は 7:1（WCAG AAA）以上を確保してある。
 */

/** 色だけを決める。文言は呼び出し側（＝PHPのenum）が渡す。 */
export type BadgeStyle = {
    className: string;
    icon?: LucideIcon;
};

/** 文言もここが持つ場合。 */
export type BadgePresentation = BadgeStyle & {
    label: string;
    /** ツールチップで補う説明。無い状態もある。 */
    help?: string;
};

/** 色を持たない中立の見た目。「変化なし」「重要度 低」に使う。 */
const NEUTRAL = 'border-border bg-muted text-foreground';

/* ------------------------------------------------------------------ *
 * 軸2 — 情報の出どころ
 * ------------------------------------------------------------------ */

/**
 * 数値としきい値から算出した指摘。
 * 色で煽る対象ではないので無彩色にしてある。紫との見分けも最大になる。
 */
const DETERMINISTIC: BadgePresentation = {
    label: '数値から算出',
    className:
        'border-deterministic-line bg-deterministic-soft text-deterministic-ink',
    icon: FunctionSquare,
    help: '記録の数値としきい値から算出しています。同じ記録なら必ず同じ結果になります。',
};

/**
 * AIが記述から読み取った指摘。
 * 紫は「人が目で確かめる必要がある」ことだけを表す。AI下書きにも同じ色を使う。
 */
const AI_DETECTED: BadgePresentation = {
    label: 'AIが検出・要確認',
    className: 'border-ai-line bg-ai-soft text-ai-ink',
    icon: Sparkles,
    help: 'AIが記述から読み取った内容です。根拠の記録をご確認のうえ、職員がご判断ください。',
};

/**
 * 検出元のバッジを組み立てる。
 *
 * 「両方で検出」に3色目を作らず、2枚並べて返す。
 * 覚える色が増えるほど取り違えが起きる。50〜60代が中心の職場で、
 * 3つ目の色の意味を記憶に頼らせたくない。
 */
export function riskSourceBadges(source: RiskSource): BadgePresentation[] {
    switch (source) {
        case 'rule_based':
            return [DETERMINISTIC];
        case 'llm_detected':
            return [AI_DETECTED];
        case 'both':
            return [DETERMINISTIC, AI_DETECTED];
    }
}

/* ------------------------------------------------------------------ *
 * 軸1 — 対応が要るか
 * ------------------------------------------------------------------ */

/** リスクの重要度。高だけベタ塗りにして、一覧の中で先に目に入るようにする。 */
export const RISK_SEVERITY: Record<RiskSeverity, BadgeStyle> = {
    high: {
        className: 'border-transparent bg-danger text-danger-foreground',
        icon: CircleAlert,
    },
    medium: {
        className: 'border-warning-line bg-warning-soft text-warning-ink',
    },
    low: {
        className: NEUTRAL,
    },
};

/**
 * 記録の入力状況。
 *
 * 「AI下書き」を確定前と別の状態にしている。AIが書いた文章を職員が
 * 一度も読んでいない記録が、確定済みに混ざってはいけないため。
 * 色は AI_DETECTED と同じ紫で、「紫が出ていたら人が確かめる」で揃える。
 */
export const RECORD_STATUS: Record<RecordStatus, BadgePresentation> = {
    confirmed: {
        label: '確定済み',
        className: 'border-success-line bg-success-soft text-success-ink',
    },
    ai_draft: {
        label: 'AI下書き・未確認',
        className: 'border-ai-line bg-ai-soft text-ai-ink',
        icon: Sparkles,
    },
    draft: {
        label: '未確定',
        className: 'border-warning-line bg-warning-soft text-warning-ink',
    },
};

/**
 * 目標の進捗評価。
 *
 * 「判断できる材料が不足」に色を与えていない。これは評価が「無い」ことの
 * 表示であって、良し悪しではないためである。破線の枠で、他と質が違うことを
 * 形で示す（要件定義 7.3節）。
 */
export const PROGRESS_STATUS: Record<ProgressStatus, BadgeStyle> = {
    improving: {
        className: 'border-success-line bg-success-soft text-success-ink',
    },
    unchanged: {
        className: NEUTRAL,
    },
    declining: {
        className: 'border-warning-line bg-warning-soft text-warning-ink',
    },
    insufficient_data: {
        className:
            'border-dashed border-foreground/50 bg-background text-foreground',
    },
};

/* ------------------------------------------------------------------ *
 * 面（バッジ以外）
 * ------------------------------------------------------------------ */

/**
 * 注意書きの囲み。ダッシュボードの口頭連絡、利用者詳細の要注意コメント、
 * 記録編集の警告に同じ見た目を使う。
 */
export const NOTICE_SURFACE = {
    warning: 'border-warning-line bg-warning-soft text-warning-ink',
    ai: 'border-ai-line bg-ai-soft text-ai-ink',
    danger: 'border-danger-line bg-danger-soft text-danger-ink',
} as const;
