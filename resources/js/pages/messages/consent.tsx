import { Form, Head } from '@inertiajs/react';
import { Check, MessagesSquare } from 'lucide-react';
import MessageConsentController from '@/actions/App/Http/Controllers/MessageConsentController';
import { PageHeader } from '@/components/care/page-header';
import { Section } from '@/components/care/section';
import { Button } from '@/components/ui/button';
import messages from '@/routes/messages';

type Props = {
    version: string;
    points: string[];
};

/**
 * 連絡を使い始めるときの承諾。
 *
 * 【画面の上の注意書きにしない】
 * いつも出ている注意書きは、数日で誰も読まなくなる。使い始める前に一度だけ、
 * 「管理者が後から読める」ことをはっきり読んでもらい、押した記録を残す。
 *
 * 文面はサーバーが持つ（MessagingTerms）。ここに書くと、記録した版と
 * 実際に見せた文面が食い違っても気づけない。
 */
export default function MessageConsent({ version, points }: Props) {
    return (
        <>
            <Head title="連絡を使う前に" />

            <div className="flex flex-col gap-4 p-4">
                <PageHeader
                    icon={MessagesSquare}
                    title="連絡を使う前に"
                    description="職員どうしの連絡は、事業所の業務の記録として扱います。"
                />

                <Section title="お読みください">
                    <ol className="list-decimal space-y-3 pl-5">
                        {points.map((point) => (
                            <li key={point} className="leading-relaxed">
                                {point}
                            </li>
                        ))}
                    </ol>

                    <Form
                        {...MessageConsentController.store.form()}
                        className="mt-6 flex flex-col gap-2 border-t pt-4"
                    >
                        {({ processing }) => (
                            <>
                                <Button
                                    type="submit"
                                    size="lg"
                                    pending={processing}
                                    className="w-full sm:w-auto sm:self-start"
                                >
                                    {!processing && (
                                        <Check className="size-5" aria-hidden />
                                    )}
                                    内容を確認し、承諾して使い始める
                                </Button>
                                <p className="text-muted-foreground text-xs">
                                    文面の版：{version}
                                    。文面が変わったときは、もう一度ご確認いただきます。
                                </p>
                            </>
                        )}
                    </Form>
                </Section>
            </div>
        </>
    );
}

MessageConsent.layout = {
    breadcrumbs: [{ title: '連絡', href: messages.consent() }],
};
