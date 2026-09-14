import { Head, Link, router } from '@inertiajs/react';
import { ArrowRight, History } from 'lucide-react';
import { EmptyState, Section } from '@/components/care/section';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import auditLogs from '@/routes/audit-logs';
import residents from '@/routes/residents';
import staff from '@/routes/staff';

type Change = {
    label: string;
    before: string;
    after: string;
};

type Log = {
    id: number;
    event: string;
    eventLabel: string;
    editor: string;
    at: string;
    ipAddress: string | null;
    subjectKind: string;
    subjectKindLabel: string;
    subjectName: string;
    subjectId: number | null;
    changes: Change[];
};

type Props = {
    type: string;
    logs: {
        data: Log[];
        currentPage: number;
        lastPage: number;
        total: number;
    };
};

const FILTERS = [
    { value: 'all', label: 'すべて' },
    { value: 'residents', label: 'ご利用者' },
    { value: 'staff', label: '職員' },
];

/**
 * 編集履歴（管理者のみ）。
 *
 * 要介護度や既往歴の書き換えは、記録の読み方そのものを変える。
 * 職員の役割変更は、誰が何を編集できるかを変える。
 * 変更した本人の記憶に頼る運用は成り立たない。
 */
export default function AuditLogIndex({ type, logs }: Props) {
    const filter = (value: string) => {
        router.get(
            auditLogs.index().url,
            value === 'all' ? {} : { type: value },
            { preserveScroll: true },
        );
    };

    return (
        <>
            <Head title="編集履歴" />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-2">
                        <History
                            className="text-muted-foreground size-4"
                            aria-hidden
                        />
                        <h1 className="text-lg font-semibold">編集履歴</h1>
                    </div>

                    <div className="flex flex-wrap gap-1">
                        {FILTERS.map((option) => (
                            <Button
                                key={option.value}
                                variant={
                                    type === option.value
                                        ? 'secondary'
                                        : 'ghost'
                                }
                                size="sm"
                                onClick={() => filter(option.value)}
                            >
                                {option.label}
                            </Button>
                        ))}
                    </div>
                </div>

                <Section
                    title="変更の記録"
                    description={
                        <>
                            ご利用者情報と職員アカウントの変更を残しています。
                            変更前の値を含むため、管理者だけが閲覧できます。
                            <span className="mt-1 block">
                                全 {logs.total.toLocaleString()} 件中{' '}
                                {logs.currentPage} / {logs.lastPage} ページ
                            </span>
                        </>
                    }
                >
                    {logs.data.length === 0 ? (
                        <EmptyState>
                            この条件の編集履歴はありません。
                        </EmptyState>
                    ) : (
                        <ul className="divide-y">
                            {logs.data.map((log) => (
                                <li
                                    key={log.id}
                                    className="py-4 first:pt-0 last:pb-0"
                                >
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Badge
                                            variant={
                                                log.event === 'created'
                                                    ? 'secondary'
                                                    : 'outline'
                                            }
                                        >
                                            {log.eventLabel}
                                        </Badge>
                                        <Badge variant="outline">
                                            {log.subjectKindLabel}
                                        </Badge>
                                        <SubjectLink log={log} />
                                    </div>

                                    <p className="text-muted-foreground mt-1 text-xs">
                                        {log.at} ／ {log.editor}
                                        {log.ipAddress &&
                                            ` ／ ${log.ipAddress}`}
                                    </p>

                                    {log.changes.length > 0 && (
                                        <ul className="mt-2 space-y-1">
                                            {log.changes.map(
                                                (change, index) => (
                                                    <li
                                                        key={`${log.id}-${change.label}-${index}`}
                                                        className="flex flex-wrap items-baseline gap-x-2 gap-y-0.5 text-sm"
                                                    >
                                                        <span className="text-muted-foreground w-40 shrink-0">
                                                            {change.label}
                                                        </span>
                                                        {/* 登録時は変更前がないので、値だけを出す */}
                                                        {log.event ===
                                                        'created' ? (
                                                            <span>
                                                                {change.after}
                                                            </span>
                                                        ) : (
                                                            <>
                                                                <span className="text-muted-foreground line-through">
                                                                    {
                                                                        change.before
                                                                    }
                                                                </span>
                                                                <ArrowRight
                                                                    className="text-muted-foreground size-3 shrink-0"
                                                                    aria-hidden
                                                                />
                                                                <span className="font-medium">
                                                                    {
                                                                        change.after
                                                                    }
                                                                </span>
                                                            </>
                                                        )}
                                                    </li>
                                                ),
                                            )}
                                        </ul>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </Section>
            </div>
        </>
    );
}

/** 変更された対象へ移動する。削除済みならリンクにしない。 */
function SubjectLink({ log }: { log: Log }) {
    if (log.subjectId === null) {
        return <span className="font-medium">{log.subjectName}</span>;
    }

    const href =
        log.subjectKind === 'resident'
            ? residents.show(log.subjectId)
            : staff.edit(log.subjectId);

    return (
        <Link href={href} className="font-medium hover:underline">
            {log.subjectName}
        </Link>
    );
}

AuditLogIndex.layout = {
    breadcrumbs: [
        { title: 'ダッシュボード', href: dashboard() },
        { title: '編集履歴', href: auditLogs.index() },
    ],
};
