import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { FlashMessage } from '@/components/flash-message';
import { LayoutHook } from '@/components/layout-hook';
import { PageHeading } from '@/components/page-heading';
import { PageTabs } from '@/components/page-tabs';
import { PlaceBar } from '@/components/place-bar';
import { ActionLink } from '@/components/ui/action-link';
import { hasPlaceBar, headerOf } from '@/lib/member-chrome';
import type { Chrome, ChromeLabel } from '@/lib/member-chrome';
import { badgePhrase } from '@/lib/count-phrase';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

export const STANDARD_COLUMN = 'max-w-[45rem]';

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
    const { component, props, url } = usePage<PageProps>();
    const header = headerOf(chrome, { component: String(component), home: props.modernHome, guest: !props.auth.user });
    const label = (l: ChromeLabel) => t(l.key, l.replacements);
    // Frame-level gate: hub actions target member-only routes, so a guest (a web-public profile is
    // reachable signed out) sees the frame without the action.
    const action = chrome.action && props.auth.user ? chrome.action : undefined;
    // A hub's h1 is heard and not seen at every width, so its action stands in the bar that shows its name.
    const hub = header.type === 'title' && header.hub;
    const actionLink = action && (
        <ActionLink href={action.href} size={hub ? 'sm' : undefined} className={hub ? 'min-h-8' : undefined}>
            <action.icon className="size-4" strokeWidth={2.25} aria-hidden />
            {label(action.label)}
        </ActionLink>
    );
    const heading = chrome.mode !== 'embedded' && chrome.title && (
        <PageHeading title={label(chrome.title)} className={hub ? 'sr-only' : undefined} action={hub ? undefined : actionLink} />
    );
    const tabs = props.auth.user ? chrome.tabs : chrome.tabs?.filter((tab) => tab.openToGuest);

    return (
        <main
            className={cn(
                // 12px below sm only: the frame is the outermost of three paddings on the same line
                // (frame → card body → field box) and gives up 4px of the usual 16 to the inner two.
                'mx-auto px-3 py-8 sm:px-4',
                hasPlaceBar(header) && 'lg:pt-(--modern-top-offset)',
                chrome.width === 'narrow' ? 'max-w-md' : STANDARD_COLUMN,
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
            {/* A hub's unseen h1 comes first, so its name is read before the action in the bar. */}
            {hub && heading}
            <PlaceBar header={header} action={hub ? actionLink : undefined} />
            {!hub && heading}
            {chrome.mode !== 'embedded' && tabs && tabs.length > 0 && (
                <PageTabs
                    ariaLabel={chrome.tabsLabel ? label(chrome.tabsLabel) : ''}
                    items={tabs.map((tab) => {
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
            {/* Keyed on the URL because the frame itself persists across visits. */}
            <LayoutHook key={`start:${url}`} name="modern.main_start" />
            {children}
            <LayoutHook key={`end:${url}`} name="modern.main_end" />
        </main>
    );
}
