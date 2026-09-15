import { RoleBadge } from '@/components/care/badges';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/hooks/use-initials';
import type { User } from '@/types';

/**
 * ログイン中の職員。サイドバー下部と、そこから開くメニューの見出しに使う。
 *
 * 役割バッジ（showRole）はサイドバー下部だけで出す。メニューの中では
 * すぐ下に同じ内容が並ぶ設定項目があり、二度出しても情報が増えない。
 * 位置は頭文字の丸より左。丸と名前は「誰か」を表し、バッジは「どの役割か」を
 * 表すので、別のものとして先に置く。
 */
export function UserInfo({
    user,
    showEmail = false,
    showRole = false,
}: {
    user: User;
    showEmail?: boolean;
    showRole?: boolean;
}) {
    const getInitials = useInitials();

    return (
        <>
            {showRole && user.role && (
                <RoleBadge
                    role={user.role}
                    /*
                     * 幅16remの中に、バッジ・頭文字の丸・氏名・開閉の記号が
                     * 並ぶ。既定の大きさのままだと「山口 みどり」でも氏名が
                     * 途中で切れる。先に詰めるのはバッジのほう。役割は一度
                     * 覚えれば形で分かるが、氏名は読めないと意味がない。
                     *
                     * 折りたたむと幅は3remになり、丸しか入らないので消す。
                     */
                    className="px-1.5 text-[0.75rem] group-data-[collapsible=icon]:hidden"
                />
            )}
            <Avatar className="h-8 w-8 overflow-hidden rounded-full">
                <AvatarImage src={user.avatar} alt={user.name} />
                <AvatarFallback className="rounded-lg bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white">
                    {getInitials(user.name)}
                </AvatarFallback>
            </Avatar>
            <div className="grid flex-1 text-left text-sm leading-tight">
                <span className="truncate font-medium">{user.name}</span>
                {showEmail && (
                    <span className="text-muted-foreground truncate text-xs">
                        {user.email}
                    </span>
                )}
            </div>
        </>
    );
}
