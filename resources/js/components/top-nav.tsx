import { Link, usePage } from '@inertiajs/react';
import { ArrowLeft, ChevronRight } from 'lucide-react';
import { type ReactNode, useEffect, useRef, useSyncExternalStore } from 'react';
import { Avatar } from '@/components/avatar';
import { AvatarMenu } from '@/components/avatar-menu';
import { BrandMark } from '@/components/brand-mark';
import { CommunityImage } from '@/components/community-image';
import { BAR_CONTROL, NavDrawer } from '@/components/nav-drawer';
import { backTarget, backTracker } from '@/lib/back-nav';
import { useT } from '@/lib/i18n';
import type { Chrome, ChromeLabel, ChromeScope } from '@/lib/member-chrome';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

/** The shell every bar variant shares — one element, one height. Height is read from
 *  `--modern-top-offset` rather than restated: the var *is* this bar's height (the top inset, which a
 *  standalone PWA draws under, is part of it), and a page's sticky header offsets by it. `hidden`
 *  slides it away while the reader scrolls down (AppShell owns the signal). */
function TopBar({ hidden, children }: { hidden?: boolean; children: ReactNode }) {
    const ref = useRef<HTMLElement>(null);

    // `inert` closes the bar to new focus, but whatever already held it keeps it (and its key
    // handlers) off-screen, so focus is dropped explicitly rather than left to the browser.
    useEffect(() => {
        const active = document.activeElement;
        if (hidden && active instanceof HTMLElement && ref.current?.contains(active)) {
            active.blur();
        }
    }, [hidden]);

    return (
        <header
            ref={ref}
            inert={hidden || undefined}
            className={cn(
                'sticky top-0 z-20 flex h-[var(--modern-top-offset)] items-center gap-2 border-b border-border bg-background/90 pt-[env(safe-area-inset-top)] pr-[calc(0.75rem+env(safe-area-inset-right))] pl-[calc(0.75rem+env(safe-area-inset-left))] backdrop-blur transition-transform duration-200 motion-reduce:transition-none lg:hidden',
                hidden && '-translate-y-full',
            )}
        >
            {children}
        </header>
    );
}

/**
 * Who the page belongs to (mark + name, the whole block one link), centered like every other middle
 * element — the member bar's one grammar is "the middle is a label; a trailing › means tapping opens
 * the thing it names" (the disclosure cue, not position, carries tappability). The mark and the
 * chevron are decorative — the name is the accessible name.
 */
function ScopeIdentity({ scope }: { scope: ChromeScope }) {
    return (
        <div className="flex min-w-0 flex-1 items-center justify-center">
            <Link
                href={scope.kind === 'community' ? `/community/${scope.id}` : `/member/${scope.id}`}
                className="flex min-w-0 max-w-full items-center gap-2"
            >
                {scope.kind === 'community' ? (
                    <CommunityImage name={scope.name} src={scope.imageUrl} className="size-8" textClassName="text-xs" decorative />
                ) : (
                    <Avatar id={scope.id} name={scope.name} src={scope.imageUrl} color={scope.avatarColor} size="sm" decorative />
                )}
                <span className="truncate font-bold">{scope.name}</span>
                <ChevronRight className="size-4 shrink-0 text-muted-foreground" aria-hidden />
            </Link>
        </div>
    );
}

/**
 * Mobile (< lg) top bar, varying by page class: hamburger + brand + account menu on the dashboard,
 * the section title in place of the brand on a hub, brand + sign-in for a guest, and back + scope on
 * a detail or form page — there the bottom nav is what carries the global links, so the bar can spend
 * its width on where the page sits.
 */
export function TopNav({ chrome, hidden }: { chrome: Chrome; hidden?: boolean }) {
    const t = useT();
    const { component, props } = usePage<PageProps>();
    const { name, auth } = props;
    const tracker = backTracker();
    // Subscribed rather than read at render: Inertia fires `navigate` after React has swapped the
    // page, so a plain read would size the new page's bar against the previous page's depth.
    const inAppHistory = useSyncExternalStore(tracker.subscribe, tracker.getSnapshot) > 0;
    const label = (l: ChromeLabel) => t(l.key, l.replacements);

    // Guest only: a guest lands from outside, where logo-left-goes-home is the web convention, and
    // has neither the bottom nav nor the drawer — this link is their one way home.
    const brand = (
        <Link href="/dashboard" className="flex min-w-0 flex-1 items-center gap-2">
            <BrandMark size="sm" />
            <span className="truncate font-bold">{name}</span>
        </Link>
    );

    // A guest has no member nav to open and no account menu, so the bar stays identity + the way in.
    if (!auth.user) {
        return (
            <TopBar hidden={hidden}>
                {brand}
                <Link
                    href="/login"
                    className="shrink-0 rounded-full px-3 py-1.5 text-sm font-medium text-link transition hover:bg-accent"
                >
                    {t('Log In')}
                </Link>
            </TopBar>
        );
    }

    // Everything that is neither the dashboard nor a hub is a detail or form page. The dashboard is
    // named rather than derived: it shares the detail pages' chrome mode (its h1 is in the page), but
    // it is the brand's home, and there is nothing above it to go back to.
    if (String(component) !== 'dashboard' && chrome.mode !== 'section') {
        const target = backTarget(inAppHistory, chrome.context);

        return (
            <TopBar hidden={hidden}>
                {target.type === 'history' ? (
                    <button
                        type="button"
                        onClick={() => window.history.back()}
                        aria-label={t('Back')}
                        className={BAR_CONTROL}
                    >
                        <ArrowLeft className="size-6" aria-hidden />
                    </button>
                ) : (
                    <Link href={target.href} aria-label={t('Back')} className={BAR_CONTROL}>
                        <ArrowLeft className="size-6" aria-hidden />
                    </Link>
                )}
                {/* The !form guard is a second belt: the registry test already pins form ⇒ no scope,
                    but a form must never carry a link beside an unsaved form even if that slips. */}
                {chrome.scope && !chrome.form ? (
                    <>
                        <ScopeIdentity scope={chrome.scope} />
                        {/* Balances the back control — same box, mirrored margins — so the identity
                            centers on the bar. */}
                        <span className="-mr-1 size-10 shrink-0" aria-hidden />
                    </>
                ) : (
                    chrome.context && (
                        <>
                            {/* No scope to be in — a form, or a message with no single counterparty.
                                The trail stays where it is as plain text, centered so the row reads
                                as a title rather than as more of the back control. */}
                            <p className="flex min-w-0 flex-1 items-center justify-center gap-1 overflow-hidden text-sm whitespace-nowrap text-muted-foreground">
                                {chrome.context.map((item, i) => (
                                    <span
                                        key={item.href}
                                        className={cn(
                                            'flex min-w-0 items-center gap-1',
                                            // The registry's shape distinction: member text arrives as a plain
                                            // string, app vocabulary as a translatable label — unless the label
                                            // interpolates member text (":name's %diary%"), as unbounded as one.
                                            typeof item.label !== 'string' && !item.label.replacements && 'shrink-0',
                                        )}
                                    >
                                        {i > 0 && (
                                            <span aria-hidden className="shrink-0">
                                                ›
                                            </span>
                                        )}
                                        <span className="min-w-0 truncate">
                                            {typeof item.label === 'string' ? item.label : label(item.label)}
                                        </span>
                                    </span>
                                ))}
                            </p>
                            {/* Balances the back control — same box, mirrored -mr-1 against its -ml-1 —
                                so the text centers on the bar, not on what is left of it. */}
                            <span className="-mr-1 size-10 shrink-0" aria-hidden />
                        </>
                    )
                )}
            </TopBar>
        );
    }

    // A hub's h1 is fixed section vocabulary (= its nav label), short enough for the bar and worth a
    // row of a phone's height, so the bar carries it and the in-page heading folds to sr-only. It is
    // aria-hidden here: that in-page h1 is the page's one announcement of the title. Centered, like
    // every static label in the bar — only tappable identity blocks sit left.
    if (chrome.mode === 'section' && chrome.title) {
        return (
            <TopBar hidden={hidden}>
                <NavDrawer />
                <span aria-hidden className="min-w-0 flex-1 truncate text-center text-base font-semibold">
                    {label(chrome.title)}
                </span>
                <AvatarMenu user={auth.user} compact />
            </TopBar>
        );
    }

    // The dashboard's brand is a label, not a link: it would only point at the page it is on, and
    // "center with no ›" meaning not-tappable is the grammar the other bars rely on. Home from
    // elsewhere is the bottom nav's job.
    return (
        <TopBar hidden={hidden}>
            <NavDrawer />
            <div className="flex min-w-0 flex-1 items-center justify-center gap-2">
                <BrandMark size="sm" />
                <span className="truncate font-bold">{name}</span>
            </div>
            <AvatarMenu user={auth.user} compact />
        </TopBar>
    );
}
