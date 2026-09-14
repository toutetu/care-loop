import { Link, usePage } from '@inertiajs/react';
import {
    Activity,
    BookOpen,
    ClipboardList,
    FolderGit2,
    LayoutGrid,
    Sparkles,
    Users,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { REPOSITORY_URL, REQUIREMENTS_URL } from '@/lib/links';
import { dashboard } from '@/routes';
import llmJobs from '@/routes/llm-jobs';
import llmLogs from '@/routes/llm-logs';
import records from '@/routes/records';
import residents from '@/routes/residents';
import type { NavItem } from '@/types';

const footerNavItems: NavItem[] = [
    {
        title: 'リポジトリ',
        href: REPOSITORY_URL,
        icon: FolderGit2,
    },
    {
        title: '要件定義書',
        href: REQUIREMENTS_URL,
        icon: BookOpen,
    },
];

type SidebarPageProps = {
    auth: { user: { role?: string } | null };
};

export function AppSidebar() {
    const { auth } = usePage<SidebarPageProps>().props;

    const mainNavItems: NavItem[] = [
        { title: 'ダッシュボード', href: dashboard(), icon: LayoutGrid },
        { title: '記録一覧', href: records.index(), icon: ClipboardList },
        { title: '利用者一覧', href: residents.index(), icon: Users },
        { title: 'AI処理の実行状況', href: llmJobs.index(), icon: Activity },
    ];

    // 費用と失敗率は運営の情報であり、日々の介護業務には要らない。
    // 権限のない職員にリンクだけ見せると、押して弾かれることになる。
    if (auth.user?.role === 'admin') {
        mainNavItems.push({
            title: 'AI利用ログ',
            href: llmLogs.index(),
            icon: Sparkles,
        });
    }

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
