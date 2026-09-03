import { Link, usePage } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { CountPill } from '@/components/count-pill';
import { headingVariants } from '@/components/ui/heading';
import {
    bottomNavSections,
    type Chrome,
    divePlace,
    isSectionActive,
    lookSpec,
    MEMBER_SEARCH_SECTION,
    type NavSection,
    NOTIFICATIONS_SECTION,
    type TabMark,
} from '@/lib/member-chrome';
import { badgePhrase } from '@/lib/count-phrase';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

/**
 * Mobile (< lg) bottom bar. Members only: a guest (a web-public profile is reachable signed out) has
 * no member nav to shortcut. `hidden` slides it away while the reader scrolls down (AppShell owns the
 * signal).
 */
export function BottomNav({ chrome, hidden }: { chrome: Chrome; hidden?: boolean }) {
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

    // Query and hash off first: a tab is matched against the pathname alone.
    const path = url.replace(/[?#].*$/, '');
    const { bottomBar, tabMark } = lookSpec(props.look);

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
            {/* A tab fills the row, so the row is the tap target, and the word under each icon is
                what makes the labelled row the taller of the two. AppShell reserves these same
                lengths as `--modern-bottom-offset`, and a bar taller than the space it was given
                stands over the page's last rows — so both are written as the same literal and
                AppShellTest reads this one back off the shell's reservation. */}
            <ul className={cn('flex items-stretch', bottomBar === 'labeled' ? 'h-[3.625rem]' : 'h-[3rem]')}>
                {bottomBar === 'dive' ? <DiveRow chrome={chrome} path={path} /> : <LabeledTabs path={path} mark={tabMark} />}
            </ul>
        </nav>
    );
}

/**
 * The section row: Home and the few sections a phone reaches for, each icon over its own word, with
 * what waits on them marked as the look says. Without it the counts live only behind the hamburger,
 * so nothing on a phone says anything is waiting. The tabs stay in the drawer too — this is a
 * shortcut, not the whole nav.
 */
function LabeledTabs({ path, mark }: { path: string; mark: TabMark }) {
    const t = useT();
    const { props } = usePage<PageProps>();

    return (
        <>
            {bottomNavSections(props.enabledFeatures).map((section) => {
                const { href, icon: Icon, label, badge } = section;
                const active = isSectionActive(section, path);
                const count = badge ? (props.unread?.[badge.count] ?? 0) : 0;
                const dotted = mark === 'dot' && href === NOTIFICATIONS_SECTION.href && count > 0;

                return (
                    <li key={href} className="flex-1">
                        <Link
                            href={href}
                            aria-current={active ? 'page' : undefined}
                            // Named by the word on screen, with the count beside it from the pill's
                            // own label. A dot prints nothing to name, so the phrase goes here — and
                            // that replaces the name rather than joining it, which stays inside
                            // WCAG 2.5.3 only because the phrase spells the word out again.
                            aria-label={dotted ? badgePhrase(t, NOTIFICATIONS_SECTION.badge, count) : undefined}
                            className={cn(
                                'flex size-full flex-col items-center justify-center gap-1 transition',
                                active ? 'text-foreground' : 'text-muted-foreground hover:text-foreground',
                            )}
                        >
                            <span className="relative inline-flex">
                                <Icon className="size-6" strokeWidth={active ? 2.25 : 2} aria-hidden />
                                {mark === 'count' && badge && (
                                    <CountPill count={count} label={badgePhrase(t, badge, count)} className="absolute -top-2 -right-2.5" />
                                )}
                                {dotted && <span aria-hidden className="absolute -top-1 -right-1 size-2 rounded-full bg-selected" />}
                            </span>
                            <span className="max-w-full truncate text-[11px] leading-none">{t(label.key, label.replacements)}</span>
                        </Link>
                    </li>
                );
            })}
        </>
    );
}

/**
 * The unified layout's row: the place the member is in, between the two errands that interrupt one.
 * The middle names where they have dived to and returns them to its top, so coming back up is one
 * tap from anywhere inside it — a group's talk, a topic, somebody's diary. It is a statement about
 * the screen rather than a section to switch to, which is why it carries a name and no icon: the
 * flanks are the same three-zone bar's tabs, and only they behave like tabs.
 */
function DiveRow({ chrome, path }: { chrome: Chrome; path: string }) {
    const t = useT();
    const { component, props } = usePage<PageProps>();
    const place = divePlace(String(component), props, chrome);

    return (
        <>
            <ZoneTab section={MEMBER_SEARCH_SECTION} shortLabel="Search" />
            <li className="flex min-w-0 flex-1 items-stretch">
                <Link
                    href={place.href}
                    // Standing on the place's own top, the link points at the page it is on: the same
                    // "you are here" the tabs say, said where the member actually is.
                    aria-current={path === place.href ? 'page' : undefined}
                    data-dive-place={place.href}
                    className="flex size-full min-w-0 items-center justify-center px-2 text-foreground"
                >
                    <span className={cn(headingVariants({ variant: 'bar' }), 'truncate')}>
                        {typeof place.label === 'string' ? place.label : t(place.label.key, place.label.replacements)}
                    </span>
                </Link>
            </li>
            <ZoneTab section={NOTIFICATIONS_SECTION} count={props.unread?.notifications ?? 0} />
        </>
    );
}

/**
 * A flanking zone, the mock's way: the icon beside its name on one line, and a dot rather than a
 * printed number when something waits (the count stays in the link's accessible name). `shortLabel`
 * is the design's own word where the nav row's is longer.
 */
function ZoneTab({ section, count = 0, shortLabel }: { section: NavSection; count?: number; shortLabel?: string }) {
    const t = useT();
    const { icon: Icon, label, badge } = section;

    return (
        <li className="flex w-24 shrink-0 items-stretch">
            <Link
                href={section.href}
                aria-label={badge && count > 0 ? badgePhrase(t, badge, count) : undefined}
                className="flex size-full min-h-11 items-center justify-center gap-1.5 px-1 text-muted-foreground transition hover:text-foreground"
            >
                <span className="relative inline-flex">
                    <Icon className="size-5" aria-hidden />
                    {count > 0 && <span aria-hidden className="absolute -top-1 -right-1 size-2 rounded-full bg-selected" />}
                </span>
                <span className="max-w-full truncate text-sm">{shortLabel ? t(shortLabel) : t(label.key, label.replacements)}</span>
            </Link>
        </li>
    );
}
