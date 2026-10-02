import { useFlashToast } from '@/hooks/use-flash-toast';
import { useAppearance } from '@/hooks/use-appearance';
import { useIsMobile } from '@/hooks/use-mobile';
import { Toaster as Sonner, type ToasterProps } from 'sonner';

function Toaster({ ...props }: ToasterProps) {
    const { appearance } = useAppearance();
    // スマートフォンでは下にタブバーがある。通知が重なると、消えるまでの
    // 数秒間タブが押せない。タブバーの高さぶん上に出す。
    const isMobile = useIsMobile();
    const aboveTabBar = isMobile
        ? { bottom: 'calc(72px + env(safe-area-inset-bottom))' }
        : undefined;

    useFlashToast();

    return (
        <Sonner
            theme={appearance}
            className="toaster group"
            position="bottom-right"
            offset={aboveTabBar}
            mobileOffset={aboveTabBar}
            style={
                {
                    '--normal-bg': 'var(--popover)',
                    '--normal-text': 'var(--popover-foreground)',
                    '--normal-border': 'var(--border)',
                } as React.CSSProperties
            }
            {...props}
        />
    );
}

export { Toaster };
