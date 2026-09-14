/**
 * 画面が受け取る業務データの型。
 *
 * コントローラが組み立てる配列と一対一で対応させている。
 * Eloquent のモデルをそのまま渡すと、暗号化した項目や内部の列名が
 * そのままJSONに出てしまうため、渡す形をここで固定している。
 */

/** 指摘や評価の根拠になった記録。原典を開けるよう記録IDを持つ。 */
export type Evidence = {
    recordId: number | null;
    date: string | null;
    excerpt: string;
};

export type RiskSeverity = 'high' | 'medium' | 'low';

export type RiskSource = 'rule_based' | 'llm_detected' | 'both';

export type RiskFinding = {
    id: number;
    category: string;
    severity: RiskSeverity;
    severityLabel: string;
    source: RiskSource;
    sourceLabel: string;
    /** ルールベース由来か。同じ入力なら必ず同じ結果になる指摘を指す。 */
    isDeterministic: boolean;
    title: string;
    reason: string;
    evidence: Evidence[];
    suggestedActions: string[];
};

export type RiskAssessment = {
    id: number;
    assessedAt: string;
    periodFrom: string;
    periodTo: string;
    noRiskDetected: boolean;
    confidence: string | null;
    isReviewed: boolean;
    findings: RiskFinding[];
};

export type ProgressStatus =
    | 'improving'
    | 'unchanged'
    | 'declining'
    | 'insufficient_data';

export type GoalProgressItem = {
    id: number;
    goalText: string | null;
    status: ProgressStatus;
    statusLabel: string;
    needsAttention: boolean;
    comment: string | null;
    evidence: Evidence[];
};

export type GoalProgress = {
    id: number;
    periodFrom: string;
    periodTo: string;
    overallSummary: string | null;
    nextActions: string[];
    confidence: string | null;
    isLowConfidence: boolean;
    items: GoalProgressItem[];
};

/** 記録の入力状況。AI下書きのまま残っている状態を独立した状態として持つ。 */
export type RecordStatus = 'confirmed' | 'draft' | 'ai_draft';

export type VerbalContact = {
    id: number;
    residentId?: number;
    residentName?: string;
    topic: string;
    reason?: string;
    urgencyLabel: string;
    recordId: number | null;
};
