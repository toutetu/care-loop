import { Link } from '@inertiajs/react';
import { MessageSquareWarning } from 'lucide-react';
import { SeverityBadge, SourceBadge } from '@/components/care/badges';
import { EmptyState, Section } from '@/components/care/section';
import { Badge } from '@/components/ui/badge';
import { NOTICE_SURFACE } from '@/lib/care-presentation';
import { cn } from '@/lib/utils';
import residents from '@/routes/residents';
import type { RiskSeverity, RiskSource, VerbalContact } from '@/types/care';

/**
 * 「要対応のリスク」と「お迎えの際にお伝えする事項」。
 *
 * PCではダッシュボードに、スマートフォンではお知らせの画面に出す。
 * 抽出条件をサーバー側で1か所にまとめた（NoticeBoard）のと同じ理由で、
 * 見た目もここ1か所で決める。片方だけ直すと、端末によって読める内容が変わる。
 */

export type UrgentRisk = {
    id: number;
    residentId: number;
    residentName: string;
    title: string;
    category: string;
    severityLabel: string;
    source: RiskSource;
    isDeterministic: boolean;
    evidenceCount: number;
    assessedOn: string;
};

export function UrgentRiskSection({ risks }: { risks: UrgentRisk[] }) {
    return (
        <Section
            title="要対応のリスク"
            description="重要度が高く、まだ職員が確認していない指摘です。根拠の記録を確認してからご判断ください。"
        >
            {risks.length === 0 ? (
                <EmptyState>未確認の重要な指摘はありません。</EmptyState>
            ) : (
                <ul className="divide-y">
                    {risks.map((risk) => (
                        <li key={risk.id} className="py-3 first:pt-0 last:pb-0">
                            <Link
                                href={residents.show(risk.residentId)}
                                className="hover:bg-accent -mx-2 block rounded px-2 py-1"
                            >
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="font-medium">
                                        {risk.residentName} 様
                                    </span>
                                    <SeverityBadge
                                        severity={'high' as RiskSeverity}
                                        label={risk.severityLabel}
                                    />
                                    <SourceBadge source={risk.source} />
                                    <Badge variant="outline">
                                        {risk.category}
                                    </Badge>
                                </div>
                                <p className="mt-1 text-sm">{risk.title}</p>
                                <p className="text-muted-foreground mt-0.5 text-xs">
                                    {risk.assessedOn} 抽出 ／ 根拠{' '}
                                    {risk.evidenceCount} 件
                                    {risk.isDeterministic
                                        ? ' ／ 数値から算出'
                                        : ' ／ 記述から検出（要確認）'}
                                </p>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </Section>
    );
}

export function VerbalContactSection({
    contacts,
}: {
    contacts: VerbalContact[];
}) {
    return (
        <Section
            title="お迎えの際にお伝えする事項"
            description="連絡帳に書いたうえで、口頭でもお伝えする内容です。お伝えしたら完了にしてください。"
        >
            {contacts.length === 0 ? (
                <EmptyState>お伝えする事項はありません。</EmptyState>
            ) : (
                <ul className="space-y-2">
                    {contacts.map((task) => (
                        <li
                            key={task.id}
                            className={cn(
                                'flex flex-wrap items-center gap-2 rounded-md border px-3 py-2 text-sm',
                                NOTICE_SURFACE.warning,
                            )}
                        >
                            <MessageSquareWarning
                                className="size-4 shrink-0"
                                aria-hidden
                            />
                            <span className="font-medium">
                                {task.residentName} 様
                            </span>
                            <span>{task.topic}</span>
                            <Badge variant="outline">{task.urgencyLabel}</Badge>
                        </li>
                    ))}
                </ul>
            )}
        </Section>
    );
}
