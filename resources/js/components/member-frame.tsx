import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { ContextHeader } from '@/components/context-header';
import { FlashMessage } from '@/components/flash-message';
import { PageHeading } from '@/components/page-heading';
import { PageTabs } from '@/components/page-tabs';
import { ActionLink } from '@/components/ui/action-link';
import { type Chrome, type ChromeLabel, resolveChrome } from '@/lib/member-chrome';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

const GAP: Record<Chrome['gap'], string> = {
    '4': 'space-y-4',
    '6': 'space-y-6',
    '8': 'space-y-8',
};

/**
 * The default page frame for every member-facing Modern page: the single <main>, the hub header
 * (h1 + primary action + tabs) resolved from the chrome registry, and central flash. Pages render
 * only their content — a page must not carry its own <main> or FlashMessage (MemberFrameGuardTest
 * enforces both), and headings outside the registry's hub modes stay in the page body ('embedded').
 *
 * `--frame-inset` is the horizontal inset this <main> spends and a bleeding Card cancels, declared here
 * so the pair cannot drift apart (FrameInsetContractTest).
 */
export function MemberFrame({ chrome: override, children }: { chrome?: Partial<Chrome>; children: ReactNode }) {
    const t = useT();
    const { component, props } = usePage<PageProps>();
    const chrome = resolveChrome(String(component), props, override);
    const label = (l: ChromeLabel) => t(l.key, l.replacements);
    // Frame-level gate: hub actions target member-only routes, so a guest (a web-public profile is
    // reachable signed out) sees the frame without the action.
    const action = chrome.action && props.auth.user ? chrome.action : undefined;

    return (
        <main
            className={cn(
                'mx-auto px-(--frame-inset) py-8 [--frame-inset:1rem]',
                chrome.width === 'narrow' ? 'max-w-md' : 'max-w-2xl',
                GAP[chrome.gap],
                chrome.foreground && 'text-foreground',
            )}
        >
            {chrome.context && (
                <ContextHeader
                    items={chrome.context.map((item) => ({
                        href: item.href,
                        label: typeof item.label === 'string' ? item.label : label(item.label),
                    }))}
                />
            )}
            {chrome.mode !== 'embedded' && chrome.title && (
                <PageHeading
                    title={label(chrome.title)}
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
                    items={chrome.tabs.map((tab) => ({ href: tab.href, label: label(tab.label), active: tab.active }))}
                />
            )}
            {props.flash.status && <FlashMessage>{props.flash.status}</FlashMessage>}
            {props.flash.error && <FlashMessage variant="error">{props.flash.error}</FlashMessage>}
            {children}
        </main>
    );
}
