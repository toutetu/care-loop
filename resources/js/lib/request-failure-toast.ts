import { router } from '@inertiajs/react';
import { toast } from 'sonner';
import llmJobs from '@/routes/llm-jobs';

/**
 * サーバーから画面を返せなかったときに、日本語で状況を出す。
 *
 * 【なぜ必要か】
 * Inertia は、想定した形式でない応答を受け取ると、その中身をそのまま
 * モーダルに出す。本番でAI処理が時間切れになったとき、Cloudflare の
 * 「Gateway time-out」という英語のページが画面いっぱいに出ていた。
 * 職員には何が起きたのかも、次に何をすればよいのかも分からない。
 *
 * 【処理が続いている可能性を伝える】
 * 時間切れは「応答を待てなくなった」ことであって、「処理が失敗した」
 * こととは限らない。サーバー側はそのまま動き続け、結果が保存される
 * ことがある。もう一度押させると、同じ処理を二重に走らせて費用だけが
 * 倍になる。先に実行状況を見てもらう。
 */
export function registerRequestFailureToast(): void {
    const show = (title: string, description: string) => {
        toast.error(title, {
            description,
            // 失敗は自動で消さない。読む前に消えては意味がない。
            duration: Infinity,
            closeButton: true,
            action: {
                label: 'AI処理の実行状況を見る',
                onClick: () => router.visit(llmJobs.index()),
            },
        });
    };

    /*
     * 画面として解釈できない応答。504 や 502、メンテナンス中のページなど、
     * アプリの手前で止められた場合がここに入る。
     */
    router.on('httpException', (event) => {
        // 既定のモーダル（受け取ったHTMLをそのまま出す）を抑える
        event.preventDefault();

        const status = event.detail.response.status;

        if (status === 504 || status === 502 || status === 408) {
            show(
                '処理に時間がかかり、応答を待てませんでした',
                'サーバー側では処理が続いていることがあります。' +
                    'もう一度実行する前に、AI処理の実行状況をご確認ください。',
            );

            return;
        }

        show(
            '通信に問題が起きました',
            '時間をおいてもう一度お試しください。' +
                '繰り返す場合は管理者にお知らせください。',
        );
    });

    /*
     * 通信そのものが成立しなかった場合。電波が切れた、回線が落ちたなど。
     * 現場ではタブレットが施設の Wi-Fi を掴み直すことがある。
     */
    router.on('networkError', (event) => {
        event.preventDefault();

        show(
            '通信できませんでした',
            'ネットワークの接続をご確認のうえ、もう一度お試しください。',
        );
    });
}
