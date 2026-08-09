import { Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, ChevronRight, X } from 'lucide-react';
import { type ComponentType, type ReactNode, useEffect, useRef, useSyncExternalStore } from 'react';
import { Avatar } from '@/components/avatar';
import { AvatarMenu } from '@/components/avatar-menu';
import { BrandMark } from '@/components/brand-mark';
import { useComposeExit, useComposeSlotRef } from '@/components/compose/compose-sheet-action';
import { CommunityImage } from '@/components/community-image';
import { BAR_CONTROL, NavDrawer } from '@/components/nav-drawer';
import { backTarget, type BackTarget, backTracker } from '@/lib/back-nav';
import { useT } from '@/lib/i18n';
import type { Chrome, ChromeLabel, ChromeScope } from '@/lib/member-chrome';
import { useScrolled } from '@/lib/use-scrolled';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

/** The 4px above and below a 36px bar action, claimed as tap target: every control in the bar
 *  answers across its full 44px height, whatever it paints — an action that filled the bar instead
 *  would leave the glyph beside it looking stranded. The compose slot spells the same rule as a
 *  descendant variant, because the actions it holds are portalled in from the page. */
const BAR_ACTION_HIT = "relative after:absolute after:inset-x-0 after:-inset-y-1 after:content-['']";

/** The shell every bar variant shares — one element, one height. Height is read from
 *  `--modern-top-offset` rather than restated: the var *is* this bar's height (the top inset, which a
 *  standalone PWA draws under, is part of it), and a page's sticky header offsets by it. `hidden`
 *  slides it away while the reader scrolls down (AppShell owns the signal). `seam` is the bottom
 *  hairline: it divides the bar from the page it floats over, so a surface that is meant to read as
 *  one piece (the compose sheet) keeps it off until content actually scrolls under the bar. */
function TopBar({ hidden, seam = true, children }: { hidden?: boolean; seam?: boolean; children: ReactNode }) {
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
                'sticky top-0 z-20 flex h-[var(--modern-top-offset)] items-center gap-2 border-b bg-background/90 pt-[env(safe-area-inset-top)] pr-[calc(0.75rem+env(safe-area-inset-right))] pl-[calc(0.75rem+env(safe-area-inset-left))] backdrop-blur transition-transform duration-200 motion-reduce:transition-none lg:hidden',
                seam ? 'border-border' : 'border-transparent',
                hidden && '-translate-y-full',
            )}
        >
            {children}
        </header>
    );
}

/**
 * The bar's leading control. One target, two faces: back on a detail or form page, close on a compose
 * sheet — where it goes to the same place, so the glyph, the label and the way it leaves are all that
 * differ. `sheet` is that way out: the surface slides back down before the navigation runs.
 */
function LeadingControl({
    target,
    label,
    icon: Icon,
    sheet,
}: {
    target: BackTarget;
    label: string;
    icon: ComponentType<{ className?: string; 'aria-hidden'?: boolean }>;
    sheet?: boolean;
}) {
    const exit = useComposeExit();
    const glyph = <Icon className="size-6" aria-hidden />;
    const leave = (navigate: () => void) => (sheet ? exit(navigate) : navigate());

    if (target.type === 'history') {
        return (
            <button type="button" onClick={() => leave(() => window.history.back())} aria-label={label} className={BAR_CONTROL}>
                {glyph}
            </button>
        );
    }

    // The sheet's link stays a link — a real href, so it reads and behaves as one (status bar, open in
    // a new tab) — and only the plain click is taken over, to play the exit before visiting. A
    // modified click is the browser's to answer, and the sheet has nothing to animate for it.
    return sheet ? (
        <a
            href={target.href}
            aria-label={label}
            className={BAR_CONTROL}
            onClick={(event) => {
                if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) {
                    return;
                }
                event.preventDefault();
                exit(() => router.visit(target.href));
            }}
        >
            {glyph}
        </a>
    ) : (
        <Link href={target.href} aria-label={label} className={BAR_CONTROL}>
            {glyph}
        </Link>
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
                // Full bar height: the block paints at 32 to sit with its name, but it is a link, and
                // the bar's targets are all 44.
                className="flex min-h-11 min-w-0 max-w-full items-center gap-2"
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
 * its width on where the page sits. A compose screen replaces that with the sheet header: close plus
 * the page's own actions.
 */
export function TopNav({ chrome, hidden }: { chrome: Chrome; hidden?: boolean }) {
    const t = useT();
    const { component, props } = usePage<PageProps>();
    const { name, auth } = props;
    const tracker = backTracker();
    const slotRef = useComposeSlotRef();
    // Only the sheet needs this: everywhere else the bar's hairline is unconditional, and a listener
    // for a border that never changes is a listener nobody asked for.
    const scrolled = useScrolled({ enabled: Boolean(chrome.compose) });
    // Subscribed rather than read at render: Inertia fires `navigate` after React has swapped the
    // page, so a plain read would size the new page's bar against the previous page's depth.
    const inAppHistory = useSyncExternalStore(tracker.subscribe, tracker.getSnapshot) > 0;
    const label = (l: ChromeLabel) => t(l.key, l.replacements);

    // Guest only: a guest lands from outside, where logo-left-goes-home is the web convention, and
    // has neither the bottom nav nor the drawer — this link is their one way home.
    const brand = (
        <Link href="/dashboard" className="flex min-h-11 min-w-0 flex-1 items-center gap-2">
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
                    className={cn(
                        'inline-flex min-h-9 shrink-0 items-center rounded-full px-3 text-sm font-medium text-link transition hover:bg-accent',
                        BAR_ACTION_HIT,
                    )}
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

        // A compose sheet spends the bar on leaving and finishing: close, then the page's own
        // action(s). No trail, no scope, no nav — the sheet is one screen with one job, and until
        // something scrolls under the bar there is no seam either: the header and the form it belongs
        // to are one surface.
        if (chrome.compose) {
            return (
                <TopBar hidden={hidden} seam={scrolled}>
                    <LeadingControl target={target} label={t('Close')} icon={X} sheet />
                    {/* The page's action(s) land here (ComposeSheetAction), pushed to the far end —
                        BAR_ACTION_HIT applied to each, since they arrive through a portal. */}
                    <div
                        ref={slotRef}
                        className="ml-auto flex items-center gap-2 [&_button]:relative [&_button::after]:absolute [&_button::after]:-inset-y-1 [&_button::after]:inset-x-0 [&_button::after]:content-['']"
                    />
                </TopBar>
            );
        }

        return (
            <TopBar hidden={hidden}>
                <LeadingControl target={target} label={t('Back')} icon={ArrowLeft} />
                {/* The !form guard is a second belt: the registry test already pins form ⇒ no scope,
                    but a form must never carry a link beside an unsaved form even if that slips. */}
                {chrome.scope && !chrome.form ? (
                    <>
                        <ScopeIdentity scope={chrome.scope} />
                        {/* Balances the back control — same box, mirrored margins — so the identity
                            centers on the bar. */}
                        <span className="-mr-1 size-11 shrink-0" aria-hidden />
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
                            <span className="-mr-1 size-11 shrink-0" aria-hidden />
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
