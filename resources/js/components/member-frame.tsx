import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { ContextHeader } from '@/components/context-header';
import { FlashMessage } from '@/components/flash-message';
import { PageHeading } from '@/components/page-heading';
import { PageTabs } from '@/components/page-tabs';
import { PlaceBar } from '@/components/place-bar';
import { ActionLink } from '@/components/ui/action-link';
import { type Chrome, type ChromeLabel, lookSpec } from '@/lib/member-chrome';
import { badgePhrase } from '@/lib/count-phrase';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

const GAP: Record<Chrome['gap'], string> = {
    '4': 'space-y-4',
    '6': 'space-y-6',
    '8': 'space-y-8',
};

/**
 * The single <main> and the central flash for every member-facing Modern page
 * (docs/internals/feature-modules.md, "Surface responsibilities").
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
                // (frame → card body → field box) and gives up 4px of the usual 16 to the inner two.
                'mx-auto px-3 py-8 sm:px-4',
                chrome.width === 'narrow' ? 'max-w-md' : 'max-w-2xl',
                GAP[chrome.gap],
                // The sheet's heading takes more air above than below it, so proximity groups it
                // with the form it heads rather than with the bar.
                chrome.compose && 'max-lg:space-y-2 max-lg:pt-5',
                // A conversation ends in its composer: below lg the composer is stuck to the foot of
                // the screen, and padding under it is a band it would come to rest on.
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
                    // Only where the mobile bar actually carries the title: a guest's bar is brand +
                    // sign-in and the unified chrome's is the tab pair, so folding for either would
                    // leave the hub with no visible heading anywhere.
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
                            countLabel: tab.badge ? badgePhrase(t, tab.badge, count) : undefined,
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
