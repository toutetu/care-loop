import { Head } from '@inertiajs/react';
import { RefreshCw, TriangleAlert } from 'lucide-react';
import { EmptyState, Section, StatCard } from '@/components/care/section';
import { Badge } from '@/components/ui/badge';
import { dashboard } from '@/routes';
import llmLogs from '@/routes/llm-logs';

type Summary = {
    month: string;
    total: number;
    failed: number;
    successRate: number | null;
    spentUsd: number;
    budgetUsd: number;
    usageRate: number;
    cacheHitRate: number;
    promptVersion: string;
    defaultModel: string;
    driver: string;
};

type FeatureRow = {
    code: string;
    label: string;
    /** その機能が実際に使ったモデル。期間内に切り替えた場合は複数並ぶ。 */
    models: string[];
    count: number;
    failed: number;
    inputTokens: number;
    outputTokens: number;
    costUsd: number;
    avgLatencyMs: number;
};

type ErrorRow = {
    type: string;
    label: string;
    count: number;
    isRetryable: boolean;
    needsOperatorAttention: boolean;
    userMessage: string | null;
};

type RequestRow = {
    id: number;
    feature: string;
    model: string;
    status: string;
    errorLabel: string | null;
    inputTokens: number | null;
    outputTokens: number | null;
    cachedTokens: number | null;
    latencyMs: number | null;
    retryCount: number | null;
    costUsd: number;
    requester: string | null;
    createdAt: string | null;
};

type Props = {
    summary: Summary;
    features: FeatureRow[];
    errors: ErrorRow[];
    requests: {
        data: RequestRow[];
        currentPage: number;
        lastPage: number;
        total: number;
    };
};

/**
 * AI利用ログ。
 *
 * LLMを組み込んだ機能は、動いているかどうかが外から見えない。
 * 失敗しても既定値が表示されるだけで、誰も気づかないまま使われ続ける。
 * 呼び出しの結果・所要時間・費用を、画面で確認できるようにしている。
 */
export default function LlmLogIndex({
    summary,
    features,
    errors,
    requests,
}: Props) {
    return (
        <>
            <Head title="AI利用ログ" />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center gap-2">
                    <h1 className="text-lg font-semibold">AI利用ログ</h1>
                    <Badge variant="outline">{summary.month}</Badge>
                    {/* APIキーが未設定のときは FakeClient が動く。実際に課金される状態か
                        どうかを、画面上ではっきりさせておく（要件定義 7.4節 #12）。 */}
                    {summary.driver === 'fake' && (
                        <Badge variant="secondary">
                            デモモード（実際のAPIは呼び出していません）
                        </Badge>
                    )}
                </div>

                <div className="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
                    <StatCard
                        label="実行回数"
                        value={summary.total}
                        unit="件"
                    />
                    <StatCard
                        label="成功率"
                        value={
                            summary.successRate !== null
                                ? `${Math.round(summary.successRate * 100)}%`
                                : '—'
                        }
                        hint={`失敗 ${summary.failed} 件`}
                        tone={summary.failed > 0 ? 'alert' : 'default'}
                    />
                    <StatCard
                        label="今月の利用料"
                        value={`$${summary.spentUsd.toFixed(4)}`}
                        hint={`上限 $${summary.budgetUsd.toFixed(2)} の ${Math.round(
                            summary.usageRate * 100,
                        )}%`}
                    />
                    <StatCard
                        label="キャッシュ利用率"
                        value={`${Math.round(summary.cacheHitRate * 100)}%`}
                        hint="入力の費用を抑える指標"
                    />
                </div>

                <Section
                    title="実行環境"
                    description="出力の品質が変わったとき、どの設定で動いていたのかを追えるようにしています。"
                >
                    <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
                        <div>
                            <dt className="text-muted-foreground">
                                既定のモデル
                            </dt>
                            <dd className="font-mono text-xs">
                                {summary.defaultModel}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">
                                プロンプト版
                            </dt>
                            <dd className="font-mono text-xs">
                                {summary.promptVersion}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">ドライバ</dt>
                            <dd className="font-mono text-xs">
                                {summary.driver}
                            </dd>
                        </div>
                    </dl>
                </Section>

                <Section
                    title="機能別の内訳"
                    description="どの機能が費用と時間を使っているかを見ます。"
                >
                    {features.length === 0 ? (
                        <EmptyState>今月の実行はまだありません。</EmptyState>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[860px] text-sm">
                                <thead>
                                    <tr className="text-muted-foreground border-b text-left text-xs">
                                        <th className="pb-2 font-medium">
                                            機能
                                        </th>
                                        <th className="pb-2 font-medium">
                                            モデル
                                        </th>
                                        <th className="pb-2 text-right font-medium">
                                            実行
                                        </th>
                                        <th className="pb-2 text-right font-medium">
                                            失敗
                                        </th>
                                        <th className="pb-2 text-right font-medium">
                                            入力
                                        </th>
                                        <th className="pb-2 text-right font-medium">
                                            出力
                                        </th>
                                        <th className="pb-2 text-right font-medium">
                                            平均応答
                                        </th>
                                        <th className="pb-2 text-right font-medium">
                                            費用
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {features.map((row) => (
                                        <tr key={row.code}>
                                            <td className="py-2">
                                                <span className="text-muted-foreground font-mono text-xs">
                                                    {row.code}
                                                </span>
                                                <span className="ml-2">
                                                    {row.label}
                                                </span>
                                            </td>
                                            <td className="py-2">
                                                {/* 期間内にモデルを切り替えると複数並ぶ。
                                                    設定が反映されたかは、ここを見れば分かる。 */}
                                                {row.models.map((model) => (
                                                    <div
                                                        key={model}
                                                        className="text-muted-foreground font-mono text-xs whitespace-nowrap"
                                                    >
                                                        {model}
                                                    </div>
                                                ))}
                                            </td>
                                            <td className="py-2 text-right tabular-nums">
                                                {row.count}
                                            </td>
                                            <td className="py-2 text-right tabular-nums">
                                                {row.failed > 0 ? (
                                                    <span className="text-danger-ink">
                                                        {row.failed}
                                                    </span>
                                                ) : (
                                                    0
                                                )}
                                            </td>
                                            <td className="py-2 text-right tabular-nums">
                                                {row.inputTokens.toLocaleString()}
                                            </td>
                                            <td className="py-2 text-right tabular-nums">
                                                {row.outputTokens.toLocaleString()}
                                            </td>
                                            <td className="py-2 text-right tabular-nums">
                                                {(
                                                    row.avgLatencyMs / 1000
                                                ).toFixed(1)}{' '}
                                                秒
                                            </td>
                                            <td className="py-2 text-right tabular-nums">
                                                ${row.costUsd.toFixed(4)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Section>

                <Section
                    title="失敗したリクエスト"
                    description="自動で再試行できる失敗と、設定の見直しが必要な失敗を分けて表示しています。"
                >
                    {errors.length === 0 ? (
                        <EmptyState>今月の失敗はありません。</EmptyState>
                    ) : (
                        <ul className="space-y-2">
                            {errors.map((row) => (
                                <li
                                    key={row.type}
                                    className="rounded-lg border p-3"
                                >
                                    <div className="flex flex-wrap items-center gap-2">
                                        <TriangleAlert
                                            className="text-warning-ink size-4 shrink-0"
                                            aria-hidden
                                        />
                                        <span className="font-medium">
                                            {row.label}
                                        </span>
                                        <span className="text-muted-foreground font-mono text-xs">
                                            {row.type}
                                        </span>
                                        <Badge
                                            variant="outline"
                                            className="tabular-nums"
                                        >
                                            {row.count} 件
                                        </Badge>
                                        {row.isRetryable && (
                                            <Badge
                                                variant="secondary"
                                                className="gap-1"
                                            >
                                                <RefreshCw
                                                    className="size-3"
                                                    aria-hidden
                                                />
                                                自動で再試行
                                            </Badge>
                                        )}
                                        {row.needsOperatorAttention && (
                                            <Badge variant="destructive">
                                                要対応
                                            </Badge>
                                        )}
                                    </div>
                                    {row.userMessage && (
                                        <p className="text-muted-foreground mt-1 text-sm">
                                            画面表示：{row.userMessage}
                                        </p>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </Section>

                <Section
                    title="呼び出しの明細"
                    description={`全 ${requests.total.toLocaleString()} 件中 ${requests.currentPage} / ${requests.lastPage} ページ`}
                >
                    {requests.data.length === 0 ? (
                        <EmptyState>記録がありません。</EmptyState>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[1000px] text-sm">
                                <thead>
                                    <tr className="text-muted-foreground border-b text-left text-xs">
                                        <th className="pb-2 font-medium">
                                            日時
                                        </th>
                                        <th className="pb-2 font-medium">
                                            機能
                                        </th>
                                        <th className="pb-2 font-medium">
                                            モデル
                                        </th>
                                        <th className="pb-2 font-medium">
                                            結果
                                        </th>
                                        <th className="pb-2 text-right font-medium">
                                            入力
                                        </th>
                                        <th className="pb-2 text-right font-medium">
                                            うちキャッシュ
                                        </th>
                                        <th className="pb-2 text-right font-medium">
                                            出力
                                        </th>
                                        <th className="pb-2 text-right font-medium">
                                            応答
                                        </th>
                                        <th className="pb-2 text-right font-medium">
                                            費用
                                        </th>
                                        <th className="pb-2 font-medium">
                                            実行者
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {requests.data.map((row) => (
                                        <tr key={row.id}>
                                            <td className="text-muted-foreground py-2 whitespace-nowrap tabular-nums">
                                                {row.createdAt ?? '—'}
                                            </td>
                                            <td className="py-2 whitespace-nowrap">
                                                {row.feature}
                                            </td>
                                            <td className="text-muted-foreground py-2 font-mono text-xs whitespace-nowrap">
                                                {row.model}
                                            </td>
                                            <td className="py-2">
                                                {row.status === 'failed' ? (
                                                    <Badge variant="destructive">
                                                        {row.errorLabel ??
                                                            '失敗'}
                                                    </Badge>
                                                ) : (
                                                    <Badge variant="secondary">
                                                        成功
                                                    </Badge>
                                                )}
                                                {(row.retryCount ?? 0) > 0 && (
                                                    <span className="text-muted-foreground ml-1 text-xs">
                                                        再試行 {row.retryCount}
                                                    </span>
                                                )}
                                            </td>
                                            <td className="py-2 text-right tabular-nums">
                                                {(
                                                    row.inputTokens ?? 0
                                                ).toLocaleString()}
                                            </td>
                                            <td className="text-muted-foreground py-2 text-right tabular-nums">
                                                {(
                                                    row.cachedTokens ?? 0
                                                ).toLocaleString()}
                                            </td>
                                            <td className="py-2 text-right tabular-nums">
                                                {(
                                                    row.outputTokens ?? 0
                                                ).toLocaleString()}
                                            </td>
                                            <td className="py-2 text-right tabular-nums">
                                                {row.latencyMs !== null
                                                    ? `${(row.latencyMs / 1000).toFixed(1)} 秒`
                                                    : '—'}
                                            </td>
                                            <td className="py-2 text-right tabular-nums">
                                                ${row.costUsd.toFixed(4)}
                                            </td>
                                            <td className="text-muted-foreground py-2 whitespace-nowrap">
                                                {row.requester ?? '—'}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Section>
            </div>
        </>
    );
}

LlmLogIndex.layout = {
    breadcrumbs: [
        { title: 'ダッシュボード', href: dashboard() },
        { title: 'AI利用ログ', href: llmLogs.index() },
    ],
};
