import { Link, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { type ReactNode, useSyncExternalStore } from 'react';
import { AvatarMenu } from '@/components/avatar-menu';
import { BrandMark } from '@/components/brand-mark';
import { ContextHeader } from '@/components/context-header';
import { NavDrawer } from '@/components/nav-drawer';
import { backTarget, backTracker } from '@/lib/back-nav';
import { useT } from '@/lib/i18n';
import type { Chrome, ChromeLabel } from '@/lib/member-chrome';
import type { PageProps } from '@/types';

/** The shell every bar variant shares — one element, one height. Height is read from
 *  `--modern-top-offset` rather than restated: the var *is* this bar's height (the top inset, which a
 *  standalone PWA draws under, is part of it), and a page's sticky header offsets by it. */
function TopBar({ children }: { children: ReactNode }) {
    return (
        <header className="sticky top-0 z-20 flex h-[var(--modern-top-offset)] items-center gap-2 border-b border-border bg-background/90 pt-[env(safe-area-inset-top)] pr-[calc(0.75rem+env(safe-area-inset-right))] pl-[calc(0.75rem+env(safe-area-inset-left))] backdrop-blur lg:hidden">
            {children}
        </header>
    );
}

const BACK_CONTROL =
    '-ml-1 inline-flex size-10 shrink-0 items-center justify-center rounded-full text-muted-foreground transition hover:bg-accent';

/**
 * Mobile (< lg) top bar, varying by page class: hamburger + brand + account menu on the dashboard and
 * on hubs, brand + sign-in for a guest, and back + crumbs on a detail or form page — there the bottom
 * nav is what carries the global links, so the bar can spend its width on where the page sits.
 */
export function TopNav({ chrome }: { chrome: Chrome }) {
    const t = useT();
    const { component, props } = usePage<PageProps>();
    const { name, auth } = props;
    const tracker = backTracker();
    // Subscribed rather than read at render: Inertia fires `navigate` after React has swapped the
    // page, so a plain read would size the new page's bar against the previous page's depth.
    const inAppHistory = useSyncExternalStore(tracker.subscribe, tracker.getSnapshot) > 0;
    const label = (l: ChromeLabel) => t(l.key, l.replacements);

    const brand = (
        <Link href="/dashboard" className="flex min-w-0 flex-1 items-center gap-2">
            <BrandMark size="sm" />
            <span className="truncate font-bold">{name}</span>
        </Link>
    );

    // A guest has no member nav to open and no account menu, so the bar stays identity + the way in.
    if (!auth.user) {
        return (
            <TopBar>
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
            <TopBar>
                {target.type === 'history' ? (
                    <button
                        type="button"
                        onClick={() => window.history.back()}
                        aria-label={t('Back')}
                        className={BACK_CONTROL}
                    >
                        <ArrowLeft className="size-6" aria-hidden />
                    </button>
                ) : (
                    <Link href={target.href} aria-label={t('Back')} className={BACK_CONTROL}>
                        <ArrowLeft className="size-6" aria-hidden />
                    </Link>
                )}
                {chrome.context && (
                    <ContextHeader
                        variant="bar"
                        items={chrome.context.map((item) => ({
                            href: item.href,
                            label: typeof item.label === 'string' ? item.label : label(item.label),
                            // The registry's shape distinction: member text arrives as a plain string,
                            // app vocabulary as a translatable label — unless the label interpolates
                            // member text itself (":name's %diary%"), which is as unbounded as a string.
                            fixedLabel: typeof item.label !== 'string' && !item.label.replacements,
                        }))}
                    />
                )}
            </TopBar>
        );
    }

    return (
        <TopBar>
            <NavDrawer />
            {brand}
            <AvatarMenu user={auth.user} compact />
        </TopBar>
    );
}
