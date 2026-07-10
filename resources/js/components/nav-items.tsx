import { Link, usePage } from '@inertiajs/react';
import { NAV_SECTIONS } from '@/lib/member-chrome';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';

/**
 * Shared nav list for LeftNav (desktop) and NavDrawer (mobile), rendered from the member chrome
 * registry — the same source the page frame reads for hub headers, so nav labels and hub h1s cannot
 * drift. Home is the brand row, so it is omitted. Friends and Messages carry an attention badge
 * (pending requests / unread) from the shared `unread` counts.
 */
export function NavItems({ onNavigate }: { onNavigate?: () => void }) {
    const t = useT();
    const { url, props } = usePage<PageProps>();
    const unread = props.unread;

    return (
        <ul className="flex flex-col gap-1">
            {NAV_SECTIONS.map(({ href, icon: Icon, label, match, badge }) => {
                const active = url.startsWith(match);
                const count = badge ? (unread?.[badge.count] ?? 0) : 0;
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
                            <span className="flex-1 truncate">{t(label.key, label.replacements)}</span>
                            {badge && count > 0 && (
                                <span
                                    className="inline-flex h-5 min-w-5 shrink-0 items-center justify-center rounded-full bg-primary px-1.5 text-[11px] font-semibold leading-none text-primary-foreground"
                                    aria-label={t(badge.label.key, { count })}
                                >
                                    {count > 99 ? '99+' : count}
                                </span>
                            )}
                        </Link>
                    </li>
                );
            })}
        </ul>
    );
}
