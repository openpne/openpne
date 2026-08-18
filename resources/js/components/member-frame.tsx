import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { ContextHeader } from '@/components/context-header';
import { FlashMessage } from '@/components/flash-message';
import { PageHeading } from '@/components/page-heading';
import { PageTabs } from '@/components/page-tabs';
import { PlaceBar } from '@/components/place-bar';
import { ActionLink } from '@/components/ui/action-link';
import { type Chrome, type ChromeLabel, lookSpec } from '@/lib/member-chrome';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

const GAP: Record<Chrome['gap'], string> = {
    '4': 'space-y-4',
    '6': 'space-y-6',
    '8': 'space-y-8',
};

/**
 * The default page frame for every member-facing Modern page: the single <main>, the crumb trail or
 * the place bar a look asks for, the hub header (h1 + primary action + tabs) from the chrome the
 * layout resolved, and central flash. Pages render
 * only their content — a page must not carry its own <main> or FlashMessage (MemberFrameGuardTest
 * enforces both), and headings outside the registry's hub modes stay in the page body ('embedded').
 */
export function MemberFrame({ chrome, children }: { chrome: Chrome; children: ReactNode }) {
    const t = useT();
    const { props } = usePage<PageProps>();
    const label = (l: ChromeLabel) => t(l.key, l.replacements);
    const look = lookSpec(props.look);
    // Frame-level gate: hub actions target member-only routes, so a guest (a web-public profile is
    // reachable signed out) sees the frame without the action.
    const action = chrome.action && props.auth.user ? chrome.action : undefined;

    return (
        <main
            className={cn(
                // 12px below sm only: the frame is the outermost of three paddings on the same line
                // (frame → card body → field box), so it gives up 4px of the usual 16 to leave the
                // inner two roomy. The strip of page background it still shows
                // is what keeps a card reading as a surface on the page rather than as the page itself.
                // From sm up it restores 16 — the width is only scarce on a phone, and `max-w` is
                // border-box, so changing it there would widen every content column too.
                'mx-auto px-3 py-8 sm:px-4',
                chrome.width === 'narrow' ? 'max-w-md' : 'max-w-2xl',
                GAP[chrome.gap],
                // The sheet's heading hangs from neither edge: enough air below the bar that it
                // does not read as part of it (20px), and a tighter gap to the form it heads (8px)
                // than a reading page's rhythm — proximity groups it with what it names.
                chrome.compose && 'max-lg:space-y-2 max-lg:pt-5',
                // A conversation ends in its composer rather than in the page's reading rhythm: below
                // lg the composer is stuck to the foot of the screen, and padding under it is the band
                // it would come to rest on — 32px of nothing between the chat bar and the screen edge
                // at exactly the scroll position the room opens at.
                chrome.conversation && 'max-lg:pb-0',
                chrome.foreground && 'text-foreground',
                // Clearance for the FAB the shell floats over this content: 56px of circle, 20px off
                // the bottom bar, so the last row stays readable under it.
                action && 'pb-24 lg:pb-8',
            )}
        >
            {look.placeBar ? (
                <PlaceBar chrome={chrome} />
            ) : (
                chrome.context && (
                    <ContextHeader
                        // Below lg the top bar carries this trail; display:none also takes it out of the
                        // accessibility tree, so only one breadcrumb landmark exists at any width.
                        className="hidden lg:flex"
                        items={chrome.context.map((item) => ({
                            href: item.href,
                            label: typeof item.label === 'string' ? item.label : label(item.label),
                        }))}
                    />
                )
            )}
            {chrome.mode !== 'embedded' && chrome.title && (
                <PageHeading
                    title={label(chrome.title)}
                    // Only where the mobile bar actually carries the title. A guest's bar is brand +
                    // sign-in and the unified chrome's is the tab pair, so folding for either would
                    // leave that hub with no visible heading anywhere.
                    fold={chrome.mode === 'section' && props.auth.user !== null && look.foldsHubHeading}
                    action={
                        action && (
                            <ActionLink href={action.href}>
                                <action.icon className="size-4" strokeWidth={2.25} aria-hidden />
                                {label(action.label)}
                            </ActionLink>
                        )
                    }
                />
            )}
            {chrome.mode !== 'embedded' && chrome.tabs && (
                <PageTabs
                    ariaLabel={chrome.tabsLabel ? label(chrome.tabsLabel) : ''}
                    items={chrome.tabs.map((tab) => {
                        // Same shared counts the nav badges read; a guest has none.
                        const count = tab.badge ? (props.unread?.[tab.badge.count] ?? 0) : 0;
                        return {
                            href: tab.href,
                            label: label(tab.label),
                            active: tab.active,
                            count,
                            countLabel: tab.badge ? t(tab.badge.label.key, { count }) : undefined,
                        };
                    })}
                />
            )}
            {props.flash.status && <FlashMessage>{props.flash.status}</FlashMessage>}
            {props.flash.error && <FlashMessage variant="error">{props.flash.error}</FlashMessage>}
            {children}
        </main>
    );
}
