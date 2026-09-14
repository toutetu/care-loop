import { Link } from '@inertiajs/react';
import { FileText, TriangleAlert } from 'lucide-react';
import records from '@/routes/records';
import type { Evidence } from '@/types/care';

/**
 * 指摘の根拠になった記録。
 *
 * 【必ず原典へ辿れるようにする】
 * AIの出力をそのまま信じて対応を決めてはいけない、というのがこのアプリの
 * 方針である。そのためには「書いてある記録をその場で開けること」が要る。
 * 開くのに3手も4手もかかるなら、誰も確認しない。
 *
 * 【根拠がないときは、ないと書く】
 * 根拠の添えられていない指摘は、本来あってはならない。隠して見た目を
 * 整えるのではなく、確認できない指摘であることを画面に出す。
 */
export function EvidenceList({ evidence }: { evidence: Evidence[] }) {
    if (evidence.length === 0) {
        return (
            <p className="flex items-center gap-1.5 text-sm text-amber-700 dark:text-amber-400">
                <TriangleAlert className="size-4 shrink-0" aria-hidden />
                根拠となる記録が添えられていません。この指摘は確認できません。
            </p>
        );
    }

    return (
        <ul className="space-y-1">
            {evidence.map((item, index) => {
                const label = (
                    <>
                        <FileText className="size-3.5 shrink-0" aria-hidden />
                        <span className="shrink-0 tabular-nums">{item.date ?? '日付不明'}</span>
                        <span className="text-muted-foreground">{item.excerpt}</span>
                    </>
                );

                return (
                    <li key={`${item.recordId ?? 'none'}-${index}`} className="text-sm">
                        {item.recordId !== null ? (
                            <Link
                                href={records.edit(item.recordId)}
                                className="flex items-start gap-1.5 rounded px-1 py-0.5 hover:bg-accent"
                            >
                                {label}
                            </Link>
                        ) : (
                            // リンクにならないことで、原典を確認できない指摘だと分かる
                            <span className="flex items-start gap-1.5 px-1 py-0.5 opacity-70">
                                {label}
                            </span>
                        )}
                    </li>
                );
            })}
        </ul>
    );
}
