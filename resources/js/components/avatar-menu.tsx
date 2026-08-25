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
import { Tip } from '@/components/ui/tooltip';
import type { AuthUser } from '@/types';

/**
 * Account menu: profile, settings, sign out. Radix DropdownMenu supplies the keyboard/focus/ARIA
 * behavior. `compact` shows just the avatar (mobile top bar); the default avatar+name row is used
 * in the desktop sidebar footer. (Appearance and language live on the settings page.)
 */
export function AvatarMenu({ user, compact = false }: { user: AuthUser; compact?: boolean }) {
    const t = useT();

    const trigger = (
        <DropdownMenuTrigger
            // Compact is the icon-only arm, where Tip below spells the name instead.
            aria-label={compact ? undefined : t('Account menu')}
            className={
                compact
                    ? // The same 48px box as the bar's other edge controls (hamburger, back), so a
                      // centered bar label sits on the true center — and the tap target grows past
                      // the 32px avatar it paints. -mr-1 mirrors their -ml-1.
                      '-mr-1 inline-flex size-12 shrink-0 items-center justify-center rounded-full outline-none focus-visible:ring-2 focus-visible:ring-ring'
                    : 'flex min-h-11 w-full items-center gap-3 rounded-full px-2 outline-none transition hover:bg-accent focus-visible:bg-accent'
            }
        >
            <Avatar id={user.id} name={user.name} src={user.imageUrl} color={user.avatarColor} isAi={user.isAi} size="sm" decorative={!compact} />
            {!compact && <span className="flex-1 truncate text-left text-sm">{user.name}</span>}
        </DropdownMenuTrigger>
    );

    return (
        <DropdownMenu>
            {/* A conditional, not `silent`: unlike the FAB, compact never toggles within a mount
                (it is the page's shape) and nothing here animates, so swapping the element type is free. */}
            {compact ? <Tip label={t('Account menu')}>{trigger}</Tip> : trigger}
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
