import { Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, ChevronRight, X } from 'lucide-react';
import { type ComponentType, type ReactNode, useEffect, useRef, useSyncExternalStore } from 'react';
import { Avatar } from '@/components/avatar';
import { AvatarMenu } from '@/components/avatar-menu';
import { BrandMark } from '@/components/brand-mark';
import { BrandName } from '@/components/brand-name';
import { useComposeExit, useComposeSlotRef } from '@/components/compose/compose-sheet-action';
import { CommunityImage } from '@/components/community-image';
import { BAR_CONTROL, NavDrawer } from '@/components/nav-drawer';
import { headingVariants } from '@/components/ui/heading';
import { Tip } from '@/components/ui/tooltip';
import { backTarget, type BackTarget, backTracker } from '@/lib/back-nav';
import { badgePhrase } from '@/lib/count-phrase';
import { markedName } from '@/lib/identity-mark';
import { useT } from '@/lib/i18n';
import {
    breadcrumbCrumb,
    type Chrome,
    type ChromeLabel,
    type ChromeScope,
    isHomeComponent,
    isPlaceTop,
    isSectionActive,
    lookSpec,
    NOTIFICATIONS_SECTION,
    unifiedTabs,
} from '@/lib/member-chrome';
import { useScrolled } from '@/lib/use-scrolled';
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
    persistent = false,
    line,
    children,
}: {
    hidden?: boolean;
    seam?: boolean;
    persistent?: boolean;
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
                'sticky top-0 z-20 flex h-[var(--modern-top-offset)] items-center gap-2 border-b bg-background/90 pt-[env(safe-area-inset-top)] pr-[calc(0.75rem+env(safe-area-inset-right))] pl-[calc(0.75rem+env(safe-area-inset-left))] backdrop-blur transition-transform duration-200 motion-reduce:transition-none',
                // The unified bar is the header at every width; the shipped bars are phone furniture.
                !persistent && 'lg:hidden',
                seam ? 'border-border' : 'border-transparent',
                // The padding restates the status-bar inset because it replaces the base padding
                // rather than adding to it.
                line !== undefined && 'pt-[calc(0.25rem+env(safe-area-inset-top))]',
                hidden && '-translate-y-full',
            )}
        >
            {children}
            {line !== undefined && (
                <span aria-hidden className="absolute inset-x-0 top-[env(safe-area-inset-top)] h-1" style={{ backgroundColor: line }} />
            )}
        </header>
    );
}

/**
 * One target with two faces: back on a detail or form page, close on a compose sheet, where `sheet`
 * plays the slide back down before the navigation runs.
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
            <Tip label={label}>
                <button type="button" onClick={() => leave(() => window.history.back())} className={BAR_CONTROL}>
                    {glyph}
                </button>
            </Tip>
        );
    }

    // A real href, so only the plain click is taken over to play the exit; a modified click is the
    // browser's to answer.
    return (
        <Tip label={label}>
            {sheet ? (
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
            ) : (
                <Link href={target.href} className={BAR_CONTROL}>
                    {glyph}
                </Link>
            )}
        </Tip>
    );
}

/**
 * The scope is the member the page is about, not the viewer, so it can be an AI account: the bar is
 * one truncating line with no room for an AiChip, so the accessible name carries the marker instead.
 * Exported only so that name can be tested; TopNav is its one caller.
 */
export function ScopeIdentity({ scope }: { scope: ChromeScope }) {
    const t = useT();
    const label = markedName(scope.name, scope.kind === 'member' && scope.isAi, t);

    return (
        <div className="flex min-w-0 flex-1 items-center justify-center">
            <Link
                href={scope.kind === 'group' ? `/groups/${scope.id}` : `/member/${scope.id}`}
                aria-label={label}
                // Full bar height: the block paints at 32 to sit with its name, but it is a link, and
                // the bar's targets are all 48.
                className="flex min-h-12 min-w-0 max-w-full items-center gap-2"
            >
                {scope.kind === 'group' ? (
                    <CommunityImage name={scope.name} src={scope.imageUrl} className="size-8" textClassName="text-xs" decorative />
                ) : (
                    <Avatar id={scope.id} name={scope.name} src={scope.imageUrl} color={scope.avatarColor} isAi={scope.isAi} size="sm" decorative />
                )}
                <span className={cn(headingVariants({ variant: 'bar' }), 'truncate')}>{scope.name}</span>
                <ChevronRight className="size-4 shrink-0 text-muted-foreground" aria-hidden />
            </Link>
        </div>
    );
}

/**
 * Only the top level: a detail, a form, a sheet and a room keep their own bar, which says where the
 * reader is (docs/internals/feature-modules.md, "Surface responsibilities").
 */
function UnifiedBar({ hidden }: { hidden?: boolean }) {
    const t = useT();
    const { url, props } = usePage<PageProps>();
    // Query and hash off first: the Home tab is matched against the whole path.
    const path = url.replace(/[?#].*$/, '');
    const notifications = props.unread?.notifications ?? 0;

    return (
        // No seam: this bar and the top of the page are one surface.
        <TopBar hidden={hidden} seam={false} persistent>
            {/* The drawer is phone furniture: at desk width the sidebar holds the same nav, and the
                design's desk header carries no hamburger beside it. */}
            <span className="lg:hidden">
                <NavDrawer />
            </span>
            {/* Not a landmark: the phone already carries one named nav (the bottom bar), and a second
                with the same name is a landmark list a reader cannot tell apart. */}
            <div className="flex min-w-0 flex-1 items-stretch justify-center">
                {unifiedTabs(props.enabledFeatures).map((section) => {
                    const active = isSectionActive(section, path);

                    return (
                        <Link
                            key={section.href}
                            href={section.href}
                            aria-current={active ? 'page' : undefined}
                            className="group relative flex min-h-12 min-w-0 items-center px-3"
                        >
                            <span
                                className={cn(
                                    headingVariants({ variant: 'bar' }),
                                    'truncate transition',
                                    !active && 'font-normal text-muted-foreground group-hover:text-foreground',
                                )}
                            >
                                {t(section.label.key, section.label.replacements)}
                            </span>
                            {/* A dot rather than an underline: at this height an underline would land
                                on the bar's own hairline and read as part of it. */}
                            {active && (
                                <span aria-hidden className="absolute inset-x-0 bottom-1.5 mx-auto size-1.5 rounded-full bg-primary" />
                            )}
                        </Link>
                    );
                })}
            </div>
            {/* The dot is not a number, so the link's name carries the count for a reader who cannot
                see it. */}
            <Tip
                label={
                    notifications > 0
                        ? badgePhrase(t, NOTIFICATIONS_SECTION.badge, notifications)
                        : t(NOTIFICATIONS_SECTION.label.key)
                }
            >
                <Link href={NOTIFICATIONS_SECTION.href} className={cn(BAR_CONTROL, 'relative')}>
                    <NOTIFICATIONS_SECTION.icon className="size-6" aria-hidden />
                    {notifications > 0 && <span aria-hidden className="absolute top-2 right-2 size-2 rounded-full bg-selected" />}
                </Link>
            </Tip>
        </TopBar>
    );
}

/**
 * The tabbed look's phone header (docs/internals/looks.md, "The registry"). A compose sheet is the
 * exception, being a mode rather than a class: it keeps its own ✕ header.
 */
function BreadcrumbBar({ chrome, hidden }: { chrome: Chrome; hidden?: boolean }) {
    const t = useT();
    const { component, props } = usePage<PageProps>();
    const label = (l: ChromeLabel) => t(l.key, l.replacements);
    const text = (l: ChromeLabel | string) => (typeof l === 'string' ? l : label(l));
    // A hub names itself; everything else names the place it is in, except the three pages that ARE
    // a place, which share one header of mark and site name.
    const brand = isHomeComponent(String(component)) || isPlaceTop(String(component));
    const hubTitle = !brand && chrome.mode === 'section' ? chrome.title : undefined;
    const crumb = brand || hubTitle ? null : breadcrumbCrumb(chrome);

    return (
        <TopBar hidden={hidden} seam={false} line={props.snsLogo.color}>
            {/* Left-aligned, not centered: a trail reads from its root, and the root is the way home. */}
            {brand ? (
                // No Tip: the site name beside the mark is the word a reader sees.
                <Link href="/" className="flex min-h-12 min-w-0 items-center gap-2">
                    <BrandMark size="sm" />
                    <BrandName className="truncate" />
                </Link>
            ) : (
                // BrandMark is aria-hidden in both its arms, so without this the link has no name at all.
                <Tip label={t('Home')}>
                    <Link href="/" className="flex min-h-12 shrink-0 items-center gap-2">
                        <BrandMark size="sm" />
                    </Link>
                </Tip>
            )}
            {(hubTitle || crumb) && (
                <span aria-hidden className="shrink-0 text-muted-foreground">
                    ›
                </span>
            )}
            {/* A hub's own h1 stays in the page under this look, so the bar's copy of it is a second
                announcement of the same words — hidden, like the shipped hub bar's. */}
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
                        <span className={cn(headingVariants({ variant: 'bar' }), 'truncate')}>{text(crumb.label)}</span>
                    </Link>
                ) : (
                    // A form's crumb goes nowhere, so it must not look as though it does.
                    <span className="min-w-0 truncate text-sm text-muted-foreground">{text(crumb.label)}</span>
                ))}
            <NavDrawer labeled />
        </TopBar>
    );
}

/**
 * The mobile (< lg) top bar, varying by page class (docs/internals/feature-modules.md, "Surface
 * responsibilities").
 */
export function TopNav({ chrome, hidden }: { chrome: Chrome; hidden?: boolean }) {
    const t = useT();
    const { component, props } = usePage<PageProps>();
    const { auth } = props;
    const tracker = backTracker();
    const slotRef = useComposeSlotRef();
    // Only the sheet needs this: everywhere else the bar's hairline is unconditional, and a listener
    // for a border that never changes is a listener nobody asked for.
    const scrolled = useScrolled({ enabled: Boolean(chrome.compose) });
    // Subscribed rather than read at render: Inertia fires `navigate` after React has swapped the
    // page, so a plain read would size the new page's bar against the previous page's depth.
    const inAppHistory = useSyncExternalStore(tracker.subscribe, tracker.getSnapshot) > 0;
    const label = (l: ChromeLabel) => t(l.key, l.replacements);

    const brand = (
        <Link href="/" className="flex min-h-12 min-w-0 flex-1 items-center gap-2">
            <BrandMark size="sm" />
            <BrandName className="truncate" />
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
                        'inline-flex min-h-9 shrink-0 items-center rounded-full px-3 text-sm text-link transition hover:bg-accent',
                        BAR_ACTION_HIT,
                    )}
                >
                    {t('Log In')}
                </Link>
            </TopBar>
        );
    }

    // Ahead of the class split below, which this look does not make; the compose sheet is excepted
    // because its ✕ is how a mode is left.
    if (lookSpec(props.look).topBar === 'breadcrumb' && !chrome.compose) {
        return <BreadcrumbBar chrome={chrome} hidden={hidden} />;
    }

    // Home and the hubs are one bar under the unified chrome; everything below them is untouched.
    const topLevel = isHomeComponent(String(component)) || chrome.mode === 'section';
    if (lookSpec(props.look).topBar === 'unified' && topLevel) {
        return <UnifiedBar hidden={hidden} />;
    }

    // Home is named rather than derived: it shares the detail pages' chrome mode, but there is
    // nothing above it to go back to.
    if (!topLevel) {
        const target = backTarget(inAppHistory, chrome.context);

        if (chrome.compose) {
            return (
                <TopBar hidden={hidden} seam={scrolled}>
                    <LeadingControl target={target} label={t('Close')} icon={X} sheet />
                    {/* BAR_ACTION_HIT as a descendant variant: the actions arrive through a portal. */}
                    <div
                        ref={slotRef}
                        className="ml-auto flex items-center gap-2 [&_button]:relative [&_button::after]:absolute [&_button::after]:-inset-y-1.5 [&_button::after]:inset-x-0 [&_button::after]:content-['']"
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
                        {/* Balances the back control, so the identity centers on the bar. */}
                        <span className="-mr-1 size-12 shrink-0" aria-hidden />
                    </>
                ) : (
                    chrome.context && (
                        <>
                            {/* No scope to be in — a form, or a message with no single counterparty. */}
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
                            {/* Balances the back control, mirrored -mr-1 against its -ml-1, so the
                                text centers on the bar. */}
                            <span className="-mr-1 size-12 shrink-0" aria-hidden />
                        </>
                    )
                )}
            </TopBar>
        );
    }

    // aria-hidden here: the in-page h1, folded to sr-only, is the page's one announcement of the title.
    if (chrome.mode === 'section' && chrome.title) {
        return (
            <TopBar hidden={hidden}>
                <NavDrawer />
                <span aria-hidden className={cn(headingVariants({ variant: 'bar' }), 'min-w-0 flex-1 truncate text-center')}>
                    {label(chrome.title)}
                </span>
                <AvatarMenu user={auth.user} compact />
            </TopBar>
        );
    }

    // Home's brand is a label, not a link: it would only point at the page it is on.
    return (
        <TopBar hidden={hidden}>
            <NavDrawer />
            <div className="flex min-w-0 flex-1 items-center justify-center gap-2">
                <BrandMark size="sm" />
                <BrandName className="truncate" />
            </div>
            <AvatarMenu user={auth.user} compact />
        </TopBar>
    );
}
