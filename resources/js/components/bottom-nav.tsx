import { Link, usePage } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { CountPill } from '@/components/count-pill';
import { bottomNavSections } from '@/lib/member-chrome';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

/**
 * Mobile (< lg) bottom tab bar: the few sections a phone reaches for, with their unread badges on
 * the first screen. Without it the counts live only behind the hamburger, so nothing on a phone says
 * anything is waiting. The tabs stay in the drawer too — this is a shortcut, not the whole nav.
 * Members only: a guest (a web-public profile is reachable signed out) has no member nav to shortcut.
 * `hidden` slides it away while the reader scrolls down (AppShell owns the signal).
 */
export function BottomNav({ hidden }: { hidden?: boolean }) {
    const t = useT();
    const { url, props } = usePage<PageProps>();
    const ref = useRef<HTMLElement>(null);

    // `inert` closes the bar to new focus, but whatever already held it keeps it (and its key
    // handlers) off-screen, so focus is dropped explicitly rather than left to the browser.
    useEffect(() => {
        const active = document.activeElement;
        if (hidden && active instanceof HTMLElement && ref.current?.contains(active)) {
            active.blur();
        }
    }, [hidden]);

    if (!props.auth.user) {
        return null;
    }

    // Exact pathname match (strip query/hash) for the tabs that ask for one; see NavSection.exact.
    const path = url.replace(/[?#].*$/, '');

    return (
        <nav
            ref={ref}
            aria-label={t('Navigation')}
            inert={hidden || undefined}
            // Side insets for landscape: the bar spans inset-x-0, so the outer tabs would otherwise
            // fall under the display cutout / corner radius.
            className={cn(
                'fixed inset-x-0 bottom-0 z-20 border-t border-border bg-background/90 pr-[env(safe-area-inset-right)] pb-[env(safe-area-inset-bottom)] pl-[env(safe-area-inset-left)] backdrop-blur transition-transform duration-200 motion-reduce:transition-none lg:hidden',
                hidden && 'translate-y-full',
            )}
        >
            {/* This row is the top bar's height — the two bands a phone always carries are one
                measure — and `--modern-bottom-offset` is it plus the hairline above and the
                safe-area inset below. A tab fills the row, so the row is the tap target. */}
            <ul className="flex h-12 items-stretch">
                {bottomNavSections(props.enabledFeatures).map(({ href, match, exact, icon: Icon, label, badge }) => {
                    const active = exact ? match.includes(path) : match.some((prefix) => path.startsWith(prefix));
                    const count = badge ? (props.unread?.[badge.count] ?? 0) : 0;
                    return (
                        <li key={href} className="flex-1">
                            <Link
                                href={href}
                                aria-current={active ? 'page' : undefined}
                                // Icon-only tabs, so the count belongs in the name rather than beside it.
                                aria-label={
                                    badge && count > 0 ? t(badge.label.key, { count }) : t(label.key, label.replacements)
                                }
                                className={
                                    'flex size-full min-h-11 items-center justify-center transition ' +
                                    (active ? 'text-foreground' : 'text-muted-foreground hover:text-foreground')
                                }
                            >
                                <span className="relative inline-flex">
                                    <Icon className="size-6" strokeWidth={active ? 2.25 : 2} aria-hidden />
                                    <CountPill count={count} className="absolute -top-2 -right-2.5" />
                                </span>
                            </Link>
                        </li>
                    );
                })}
            </ul>
        </nav>
    );
}
