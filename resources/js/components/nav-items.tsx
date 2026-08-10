import { Link, usePage } from '@inertiajs/react';
import { CountPill } from '@/components/count-pill';
import { visibleNavSections } from '@/lib/member-chrome';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';

/**
 * Shared nav list for LeftNav (desktop) and NavDrawer (mobile), rendered from the member chrome
 * registry — the same source the page frame reads for hub headers, so nav labels and hub h1s cannot
 * drift. Home is the brand row, so it is omitted. Friends and Messages carry an attention badge
 * (pending requests / unread) from the shared `unread` counts. Sections of a switched-off unit are
 * dropped (the Classic navigation does the same from its own route table).
 */
export function NavItems({ onNavigate }: { onNavigate?: () => void }) {
    const t = useT();
    const { url, props } = usePage<PageProps>();
    const unread = props.unread;

    return (
        <ul className="flex flex-col gap-1">
            {visibleNavSections(props.enabledFeatures).map(({ href, icon: Icon, label, match, badge }) => {
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
                                    ? 'bg-accent text-foreground'
                                    : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground')
                            }
                        >
                            <Icon className="size-5 shrink-0" strokeWidth={active ? 2.25 : 2} />
                            <span className="flex-1 truncate">{t(label.key, label.replacements)}</span>
                            {badge && <CountPill count={count} label={t(badge.label.key, { count })} />}
                        </Link>
                    </li>
                );
            })}
        </ul>
    );
}
