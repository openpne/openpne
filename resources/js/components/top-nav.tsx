import { Link, router, usePage } from '@inertiajs/react';
import { X } from 'lucide-react';
import { type CSSProperties, type ReactNode, useEffect, useRef, useSyncExternalStore } from 'react';
import { BrandMark } from '@/components/brand-mark';
import { BrandName } from '@/components/brand-name';
import { useComposeExit, useComposeSlotRef } from '@/components/compose/compose-sheet-action';
import { BAR_CONTROL, NavDrawer } from '@/components/nav-drawer';
import { PlaceSwitch } from '@/components/place-switch';
import { headingVariants } from '@/components/ui/heading';
import { Tip } from '@/components/ui/tooltip';
import { backTarget, type BackTarget, backTracker } from '@/lib/back-nav';
import { useT } from '@/lib/i18n';
import { type Chrome, type ChromeLabel, headerOf } from '@/lib/member-chrome';
import { useScrolled } from '@/lib/use-scrolled';
import { pressPrefetch } from '@/lib/press-prefetch';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

/** Claims the 6px above and below a 36px action, so every control in the bar answers across its
 *  full 48px height. The compose slot spells the same rule as a descendant variant, its actions
 *  arriving through a portal. */
const BAR_ACTION_HIT = "relative after:absolute after:inset-x-0 after:-inset-y-1.5 after:content-['']";

/** The height is read from `--modern-top-offset` rather than restated: the var is this bar's height,
 *  the top inset included, and a page's sticky header offsets by it. */
function TopBar({
    hidden,
    seam = true,
    line,
    children,
}: {
    hidden?: boolean;
    seam?: boolean;
    /** Site color for the 4px line atop the row, which is part of the bar: the height var counts it
     *  and it slides away with the bar. */
    line?: string;
    children: ReactNode;
}) {
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
                'sticky top-0 z-20 flex h-(--modern-top-offset) items-center gap-2 border-b bg-background/90 pt-safe pr-safe-3 pl-safe-3 backdrop-blur transition-transform duration-200 motion-reduce:transition-none lg:hidden',
                seam ? 'border-border' : 'border-transparent',
                // The padding restates the status-bar inset because it replaces the base padding
                // rather than adding to it.
                line !== undefined && 'pt-safe-1',
                hidden && '-translate-y-full',
            )}
        >
            {children}
            {line !== undefined && (
                <span aria-hidden className="absolute inset-x-0 top-safe h-1 bg-(--site-color)" style={{ '--site-color': line } as CSSProperties} />
            )}
        </header>
    );
}

/** The compose sheet's way out: the slide back down plays before the navigation runs. */
function SheetClose({ target, label }: { target: BackTarget; label: string }) {
    const exit = useComposeExit();
    const glyph = <X className="size-6" aria-hidden />;

    if (target.type === 'history') {
        return (
            <Tip label={label}>
                <button type="button" onClick={() => exit(() => window.history.back())} className={BAR_CONTROL}>
                    {glyph}
                </button>
            </Tip>
        );
    }

    // A real href, so only the plain click is taken over to play the exit; a modified click is the
    // browser's to answer.
    return (
        <Tip label={label}>
            <a
                href={target.href}
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
        </Tip>
    );
}

const GUEST_ENTRY = 'inline-flex min-h-9 shrink-0 items-center rounded-full px-3 text-sm whitespace-nowrap';

/** Where a member's bar ends in the menu, a guest's ends in the way in. */
function GuestEntry({ className }: { className?: string }) {
    const t = useT();

    return (
        <Link href="/login" className={cn(GUEST_ENTRY, 'text-link transition hover:bg-accent', BAR_ACTION_HIT, className)}>
            {t('Log In')}
        </Link>
    );
}

/** The way in's width without the way in, so the column across from it is held as wide. */
function GuestEntryGhost() {
    const t = useT();

    return (
        <span aria-hidden className={cn(GUEST_ENTRY, 'invisible')}>
            {t('Log In')}
        </span>
    );
}

function MarkHome() {
    const t = useT();

    return (
        // BrandMark is aria-hidden in both its arms, so without this the link has no name at all.
        <Tip label={t('Home')}>
            <Link href="/" {...pressPrefetch('/')} className="flex min-h-12 shrink-0 items-center gap-2 justify-self-start">
                <BrandMark size="sm" />
            </Link>
        </Tip>
    );
}

/** The site mark, then where the reader is (docs/internals/modern-frame.md, "The phone header"). */
function BreadcrumbBar({ chrome, hidden }: { chrome: Chrome; hidden?: boolean }) {
    const t = useT();
    const { component, props } = usePage<PageProps>();
    const label = (l: ChromeLabel) => t(l.key, l.replacements);
    const text = (l: ChromeLabel | string) => (typeof l === 'string' ? l : label(l));
    const guest = props.auth.user === null;
    const header = headerOf(chrome, { component: String(component), home: props.modernHome, guest });
    const end = guest ? <GuestEntry className="ml-auto" /> : <NavDrawer />;

    if (header.type === 'place') {
        return (
            <TopBar hidden={hidden} seam={false} line={props.snsLogo.color}>
                {/* Each outer column is as wide as the wider of the two ends — the menu's 44px, or a guest's way in ghosted under the mark — so even a name that fills the row stays at the middle of the screen. */}
                <div className="grid min-w-0 flex-1 grid-cols-[minmax(min-content,1fr)_minmax(0,auto)_minmax(min-content,1fr)] items-center gap-2">
                    <div className="grid min-w-11 items-center justify-items-start *:[grid-area:1/1]">
                        <MarkHome />
                        {guest && <GuestEntryGhost />}
                    </div>
                    {header.pressable ? (
                        <PlaceSwitch scope={header.scope} variant="phone" />
                    ) : (
                        <span className={cn(headingVariants({ variant: 'bar' }), 'min-w-0 truncate px-1 text-center')}>{header.scope.name}</span>
                    )}
                    <div className="flex min-w-11 justify-end">{end}</div>
                </div>
            </TopBar>
        );
    }

    const brand = header.type === 'brand';
    // A hub names itself; everything else names where it is.
    const hubTitle = !brand && chrome.mode === 'section' ? chrome.title : undefined;
    const crumb = header.type === 'trail' && !hubTitle ? header.crumb : null;

    return (
        <TopBar hidden={hidden} seam={false} line={props.snsLogo.color}>
            {/* Left-aligned, not centered: a trail reads from its root, and the root is the way home. */}
            {brand ? (
                // No Tip: the site name beside the mark is the word a reader sees.
                <Link href="/" {...pressPrefetch('/')} className="flex min-h-12 min-w-0 items-center gap-2">
                    <BrandMark size="sm" />
                    <BrandName className="truncate" />
                </Link>
            ) : (
                <MarkHome />
            )}
            {(hubTitle || crumb) && (
                <span aria-hidden className="shrink-0 text-muted-foreground">
                    ›
                </span>
            )}
            {/* A hub's own h1 stays in the page, so the bar's copy of it would be a second
                announcement of the same words. */}
            {hubTitle && (
                <span aria-hidden className={cn(headingVariants({ variant: 'bar' }), 'min-w-0 truncate')}>
                    {label(hubTitle)}
                </span>
            )}
            {crumb &&
                (crumb.link ? (
                    // Pressable, so it is painted as pressable: the pill is the affordance a bare
                    // trailing › was not.
                    <Link href={crumb.href} className="flex min-h-11 min-w-0 items-center rounded-full bg-accent px-3">
                        <span className={cn(headingVariants({ variant: 'bar' }), 'min-w-0 truncate')}>{text(crumb.label)}</span>
                    </Link>
                ) : (
                    // A form's crumb goes nowhere, so it must not look as though it does.
                    <span className="min-w-0 truncate text-sm text-muted-foreground">{text(crumb.label)}</span>
                ))}
            {end}
        </TopBar>
    );
}

/**
 * The mobile (< lg) top bar: one grammar on every screen class, a compose sheet excepted because its
 * ✕ is how a mode is left (docs/internals/modern-frame.md, "The phone header").
 */
export function TopNav({ chrome, hidden }: { chrome: Chrome; hidden?: boolean }) {
    return chrome.compose ? <SheetBar chrome={chrome} hidden={hidden} /> : <BreadcrumbBar chrome={chrome} hidden={hidden} />;
}

function SheetBar({ chrome, hidden }: { chrome: Chrome; hidden?: boolean }) {
    const t = useT();
    const tracker = backTracker();
    const slotRef = useComposeSlotRef();
    const scrolled = useScrolled();
    // Subscribed rather than read at render: Inertia fires `navigate` after React has swapped the
    // page, so a plain read would size the new page's bar against the previous page's depth.
    const inAppHistory = useSyncExternalStore(tracker.subscribe, tracker.getSnapshot) > 0;

    return (
        <TopBar hidden={hidden} seam={scrolled}>
            <SheetClose target={backTarget(inAppHistory, chrome.context)} label={t('Close')} />
            {/* BAR_ACTION_HIT as a descendant variant: the actions arrive through a portal. */}
            <div
                ref={slotRef}
                className="ml-auto flex items-center gap-2 [&_button]:relative [&_button::after]:absolute [&_button::after]:-inset-y-1.5 [&_button::after]:inset-x-0 [&_button::after]:content-['']"
            />
        </TopBar>
    );
}
