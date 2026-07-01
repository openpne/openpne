import { Link, router, usePage } from '@inertiajs/react';
import { Globe, LogOut, Settings, User } from 'lucide-react';
import { useT } from '@/lib/i18n';
import { Avatar } from '@/components/avatar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { AuthUser, PageProps } from '@/types';

const NATIVE_LOCALE_LABEL: Record<string, string> = { ja: '日本語', en: 'English' };

/**
 * Account menu: profile, settings, language toggle, sign out. Radix DropdownMenu supplies the
 * keyboard/focus/ARIA behaviour. `compact` shows just the avatar (mobile top bar); the default
 * avatar+name row is used in the desktop sidebar footer.
 */
export function AvatarMenu({ user, compact = false }: { user: AuthUser; compact?: boolean }) {
    const t = useT();
    const locale = usePage<PageProps>().props.locale;
    const nextLocale = locale === 'ja' ? 'en' : 'ja';

    return (
        <DropdownMenu>
            <DropdownMenuTrigger
                aria-label={t('Account menu')}
                className={
                    compact
                        ? 'shrink-0 rounded-full outline-none focus-visible:ring-2 focus-visible:ring-slate-400'
                        : 'flex min-h-11 w-full items-center gap-3 rounded-full px-2 outline-none transition hover:bg-slate-100 focus-visible:bg-slate-100 dark:hover:bg-slate-800 dark:focus-visible:bg-slate-800'
                }
            >
                <Avatar id={user.id} name={user.name} src={user.imageUrl} size="sm" />
                {!compact && <span className="flex-1 truncate text-left text-sm font-medium">{user.name}</span>}
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" side="top" className="w-64">
                <DropdownMenuItem asChild>
                    <Link href={`/m/member/${user.id}`}>
                        <User className="size-4 shrink-0 text-slate-500 dark:text-slate-400" />
                        <span className="flex-1">{t('View my profile')}</span>
                    </Link>
                </DropdownMenuItem>
                <DropdownMenuItem asChild>
                    <Link href="/m/member/config">
                        <Settings className="size-4 shrink-0 text-slate-500 dark:text-slate-400" />
                        <span className="flex-1">{t('Settings')}</span>
                    </Link>
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem onSelect={() => router.post('/locale', { locale: nextLocale })}>
                    <Globe className="size-4 shrink-0 text-slate-500 dark:text-slate-400" />
                    <span className="flex-1">{NATIVE_LOCALE_LABEL[nextLocale]}</span>
                </DropdownMenuItem>
                <DropdownMenuItem onSelect={() => router.post('/logout')}>
                    <LogOut className="size-4 shrink-0 text-slate-500 dark:text-slate-400" />
                    <span className="flex-1">{t('Sign out')}</span>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
