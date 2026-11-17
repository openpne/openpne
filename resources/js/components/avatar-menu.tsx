import { Link, router } from '@inertiajs/react';
import { LogOut, Settings, User } from 'lucide-react';
import { useT } from '@/lib/i18n';
import { Avatar } from '@/components/avatar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { AuthUser } from '@/types';

export function AvatarMenu({ user }: { user: AuthUser }) {
    const t = useT();

    return (
        <DropdownMenu>
            <DropdownMenuTrigger
                aria-label={t('Account menu')}
                className="flex min-h-11 w-full items-center gap-3 rounded-full px-2 outline-none transition hover:bg-accent focus-visible:bg-accent"
            >
                <Avatar id={user.id} name={user.name} src={user.imageUrl} color={user.avatarColor} isAi={user.isAi} size="sm" decorative />
                <span className="flex-1 truncate text-left text-sm">{user.name}</span>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" side="top" className="w-64">
                <DropdownMenuItem asChild>
                    <Link href={`/member/${user.id}`}>
                        <User className="size-4 shrink-0 text-muted-foreground" />
                        <span className="flex-1">{t('View my profile')}</span>
                    </Link>
                </DropdownMenuItem>
                <DropdownMenuItem asChild>
                    <Link href="/member/config">
                        <Settings className="size-4 shrink-0 text-muted-foreground" />
                        <span className="flex-1">{t('Settings')}</span>
                    </Link>
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem onSelect={() => router.post('/logout')}>
                    <LogOut className="size-4 shrink-0 text-muted-foreground" />
                    <span className="flex-1">{t('Sign out')}</span>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
