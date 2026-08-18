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
    tabbedNavSections,
} from '@/lib/member-chrome';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

/**
 * Mobile (< lg) bottom bar. Members only: a guest (a web-public profile is reachable signed out) has
 * no member nav to shortcut. `hidden` slides it away while the reader scrolls down (AppShell owns the
 * signal). The shell — its height, its insets, the space it reserves — is the same whichever row
 * stands in it, so only what the row says changes.
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

    // Exact pathname match (strip query/hash) for the tabs that ask for one; see NavSection.exact.
    const path = url.replace(/[?#].*$/, '');
    const { bottomBar } = lookSpec(props.look);

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
                safe-area inset below. A tab fills the row, so the row is the tap target. A label
                under each icon is the one row that needs more than that height. */}
            <ul className={cn('flex items-stretch', bottomBar === 'labeled' ? 'h-[3.625rem]' : 'h-12')}>
                {bottomBar === 'dive' ? (
                    <DiveRow chrome={chrome} path={path} />
                ) : bottomBar === 'labeled' ? (
                    <LabeledTabs path={path} />
                ) : (
                    <SectionTabs path={path} />
                )}
            </ul>
        </nav>
    );
}

/**
 * The few sections a phone reaches for, with their unread badges on the first screen. Without it the
 * counts live only behind the hamburger, so nothing on a phone says anything is waiting. The tabs
 * stay in the drawer too — this is a shortcut, not the whole nav.
 */
function SectionTabs({ path }: { path: string }) {
    const t = useT();
    const { props } = usePage<PageProps>();

    return (
        <>
            {bottomNavSections(props.enabledFeatures).map((section) => {
                const { href, icon: Icon, label, badge } = section;
                const active = isSectionActive(section, path);
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
        </>
    );
}

/**
 * The tabbed look's row: four sections, each icon over its own full word. The words are why there
 * are four rather than five — messages steps out, its count being ambient state the drawer entry
 * already carries — and why the row is taller than the others.
 *
 * One badge, and it is a dot: notifications is the only queue here, a queue empties by being read,
 * and how many is the notification screen's answer rather than the bar's. The counts that stay put
 * whatever the reader does (rooms with new talk, pending requests) are state, not a summons, and
 * they keep their pills in the drawer.
 */
function LabeledTabs({ path }: { path: string }) {
    const t = useT();
    const { props } = usePage<PageProps>();
    const notifications = props.unread?.notifications ?? 0;

    return (
        <>
            {tabbedNavSections(props.enabledFeatures).map((section) => {
                const { href, icon: Icon, label } = section;
                const active = isSectionActive(section, path);
                const dotted = href === NOTIFICATIONS_SECTION.href && notifications > 0;

                return (
                    <li key={href} className="flex-1">
                        <Link
                            href={href}
                            aria-current={active ? 'page' : undefined}
                            // The visible word is the name; the dot's count joins it rather than
                            // replacing it, since the word is still on screen beside the number.
                            aria-label={dotted ? t(NOTIFICATIONS_SECTION.badge.label.key, { count: notifications }) : undefined}
                            className={cn(
                                'flex size-full flex-col items-center justify-center gap-1 transition',
                                active ? 'text-foreground' : 'text-muted-foreground hover:text-foreground',
                            )}
                        >
                            <span className="relative inline-flex">
                                <Icon className="size-6" strokeWidth={active ? 2.25 : 2} aria-hidden />
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
                aria-label={badge && count > 0 ? t(badge.label.key, { count }) : undefined}
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
