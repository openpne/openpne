import type { ComponentType } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { Activity, BookOpen, Search, Settings, UserCircle2, Users } from 'lucide-react';
import { useT } from '@/lib/i18n';

type Item = {
    href: string;
    icon: ComponentType<{ className?: string; strokeWidth?: number }>;
    label: string;
    match: string;
};

/**
 * Shared nav list for LeftNav (desktop) and NavDrawer (mobile). Home is the brand row, so it is
 * omitted here. Only features with a live Modern surface are listed; Messages joins when its
 * Modern screens land.
 */
export function NavItems({ onNavigate }: { onNavigate?: () => void }) {
    const t = useT();
    const url = usePage().url;

    const items: Item[] = [
        { href: '/m/diary/list', icon: BookOpen, label: t('Diaries'), match: '/m/diary' },
        { href: '/m/timeline', icon: Activity, label: t('Timeline'), match: '/m/timeline' },
        { href: '/m/community/search', icon: Users, label: t('%Communities%'), match: '/m/community' },
        { href: '/m/friend/list', icon: UserCircle2, label: t('%Friends%'), match: '/m/friend' },
        { href: '/m/member/search', icon: Search, label: t('Search members'), match: '/m/member/search' },
        { href: '/m/member/config', icon: Settings, label: t('Settings'), match: '/m/member/config' },
    ];

    return (
        <ul className="flex flex-col gap-1">
            {items.map(({ href, icon: Icon, label, match }) => {
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
                                    ? 'bg-slate-100 font-semibold text-slate-900 dark:bg-slate-800 dark:text-slate-100'
                                    : 'text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-800')
                            }
                        >
                            <Icon className="size-5 shrink-0" strokeWidth={active ? 2.25 : 2} />
                            <span className="flex-1 truncate">{label}</span>
                        </Link>
                    </li>
                );
            })}
        </ul>
    );
}
