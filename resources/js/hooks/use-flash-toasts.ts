import { usePage } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { toast } from 'sonner';

type FlashProps = {
    flash?: {
        success?: string | null;
        error?: string | null;
    };
};

/**
 * サーバーから返ってきたフラッシュメッセージをトーストで出す。
 *
 * AI機能は失敗することがあり、そのときに何も起きないように見えるのが
 * いちばん困る。成功も失敗も必ず画面に出す。
 *
 * 失敗は自動で消さない。「混み合っています」と「管理者に連絡してください」
 * では取るべき行動が違うので、読む前に消えてしまっては意味がない。
 */
export function useFlashToasts() {
    const { flash } = usePage<FlashProps>().props;
    const shown = useRef<string | null>(null);

    useEffect(() => {
        const message = flash?.error ?? flash?.success;

        if (!message) {
            shown.current = null;
            return;
        }

        // 同じメッセージを二重に出さない。部分リロードでも props は届く。
        const key = `${flash?.error ? 'error' : 'success'}:${message}`;

        if (shown.current === key) {
            return;
        }

        shown.current = key;

        if (flash?.error) {
            toast.error(flash.error, { duration: Infinity, closeButton: true });
        } else {
            toast.success(message);
        }
    }, [flash]);
}
