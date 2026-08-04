import { Link, usePage } from '@inertiajs/react';
import { useT } from '@/lib/i18n';
import type { Chrome, ChromeAction } from '@/lib/member-chrome';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

/**
 * Mobile (< lg) primary action: the screen's registry action, floating above the bottom bar. It
 * carries its label because a bare icon reads as one fixed global verb and so misleads on whichever
 * screen it happens to float over; it collapses to the icon on the same signal that takes the bars
 * away, spending the width only while the reader is not reading. A screen with no registry action
 * shows nothing. Desktop keeps the same action as the heading-row button, so this is hidden at lg+.
 */
export function ActionFab({ chrome, extended }: { chrome: Chrome; extended: boolean }) {
    const { props } = usePage<PageProps>();

    // The frame's gate verbatim: the heading-row button and this are one action at two widths, and a
    // guest (a web-public profile is reachable signed out) gets neither.
    if (!chrome.action || !props.auth.user) {
        return null;
    }

    return <Fab action={chrome.action} extended={extended} />;
}

function Fab({ action, extended }: { action: ChromeAction; extended: boolean }) {
    const t = useT();
    const label = t(action.label.key, action.label.replacements);

    // The nav landmark keeps this action inside a region (axe) and carries the fixed positioning, so
    // no blurred/transformed ancestor becomes its containing block.
    return (
        <nav
            aria-label={label}
            className="fixed right-[calc(1.25rem+env(safe-area-inset-right))] bottom-[calc(1.25rem+var(--modern-bottom-offset))] z-30 lg:hidden"
        >
            <Link
                href={action.href}
                // The full label in both states: collapsing is a visual economy, so the control's
                // name must not travel with it.
                aria-label={label}
                // px-4 in both states — 16 + the 24px icon + 16 is exactly the 56px circle the
                // collapsed state wants, so the pill never animates its own width, only the label does.
                className="inline-flex h-14 items-center rounded-full bg-primary px-4 text-primary-foreground shadow-lg transition hover:bg-primary/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background active:scale-[0.97]"
            >
                <action.icon className="size-6" strokeWidth={2.25} aria-hidden />
                <span
                    className={cn(
                        'overflow-hidden font-medium whitespace-nowrap transition-[max-width,margin,opacity] motion-reduce:transition-none',
                        extended ? 'ml-2 max-w-48 opacity-100' : 'ml-0 max-w-0 opacity-0',
                    )}
                >
                    {label}
                </span>
            </Link>
        </nav>
    );
}
