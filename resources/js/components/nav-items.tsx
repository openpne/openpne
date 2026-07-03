import type { ComponentType } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { Activity, BookOpen, Mail, Search, Settings, UserCircle2, Users } from 'lucide-react';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';

type Item = {
    href: string;
    icon: ComponentType<{ className?: string; strokeWidth?: number }>;
    label: string;
    match: string;
    badge?: { count: number; label: string };
};

/**
 * Shared nav list for LeftNav (desktop) and NavDrawer (mobile). Home is the brand row, so it is
 * omitted here. Friends and Messages carry an attention badge (pending requests / unread) from the
 * shared `unread` counts.
 */
export function NavItems({ onNavigate }: { onNavigate?: () => void }) {
    const t = useT();
    const { url, props } = usePage<PageProps>();
    const unread = props.unread;

    const items: Item[] = [
        { href: '/m/diary/list', icon: BookOpen, label: t('Diaries'), match: '/m/diary' },
        { href: '/m/community/search', icon: Users, label: t('%Communities%'), match: '/m/community' },
        { href: '/m/timeline', icon: Activity, label: t('Timeline'), match: '/m/timeline' },
        {
            href: '/m/friend/list',
            icon: UserCircle2,
            label: t('%Friends%'),
            match: '/m/friend',
            badge: {
                count: unread?.friendRequests ?? 0,
                label: t(':count pending %friend% requests', { count: unread?.friendRequests ?? 0 }),
            },
        },
        {
            href: '/m/message',
            icon: Mail,
            label: t('Messages'),
            match: '/m/message',
            badge: {
                count: unread?.unreadMessages ?? 0,
                label: t(':count unread messages', { count: unread?.unreadMessages ?? 0 }),
            },
        },
        { href: '/m/member/search', icon: Search, label: t('Search members'), match: '/m/member/search' },
        { href: '/m/member/config', icon: Settings, label: t('Settings'), match: '/m/member/config' },
    ];

    return (
        <ul className="flex flex-col gap-1">
            {items.map(({ href, icon: Icon, label, match, badge }) => {
                const active = url.startsWith(match);
                return (
                    <li key={href}>
                        <Link
                            href={href}
                            onClick={onNavigate}
                            aria-current={active ? 'page' : undefined}
                            className={
                                'flex min-h-11 items-center gap-3 rounded-full px-3 text-base transition ' +
                                (active
                                    ? 'bg-accent font-semibold text-foreground'
                                    : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground')
                            }
                        >
                            <Icon className="size-5 shrink-0" strokeWidth={active ? 2.25 : 2} />
                            <span className="flex-1 truncate">{label}</span>
                            {badge && badge.count > 0 && (
                                <span
                                    className="inline-flex h-5 min-w-5 shrink-0 items-center justify-center rounded-full bg-primary px-1.5 text-[11px] font-semibold leading-none text-primary-foreground"
                                    aria-label={badge.label}
                                >
                                    {badge.count > 99 ? '99+' : badge.count}
                                </span>
                            )}
                        </Link>
                    </li>
                );
            })}
        </ul>
    );
}
