import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { ActionFab } from '@/components/action-fab';
import { BottomNav } from '@/components/bottom-nav';
import { ComposeSheetProvider, useComposeExitState } from '@/components/compose/compose-sheet-action';
import { ConfirmDialogHost } from '@/components/confirm-dialog';
import { LeftNav } from '@/components/left-nav';
import { RightRail } from '@/components/right-rail';
import { TopNav } from '@/components/top-nav';
import { UnreadSync } from '@/components/unread-sync';
import { type Chrome, chromeRecedes, hasBottomNav } from '@/lib/member-chrome';
import { useScrollDirection } from '@/lib/use-scroll-direction';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

/**
 * Modern app shell: a desktop sidebar + mobile top and bottom bars around the page, plus a right rail
 * at xl+. The shell is nav chrome only — each page keeps its own <main> and flash, so wrapping an
 * existing page adds navigation without a nested <main> or duplicate flash. `--modern-top-offset` lets
 * a page's sticky header sit below the mobile top bar, `--modern-bottom-offset` keeps fixed/scrolled
 * content clear of the bottom bar (both 0 on desktop, where the bars are hidden). The frame widens at
 * xl to seat the third column without squeezing the centered page content. The resolved chrome comes
 * from the layout (MemberFrame gets the same object): the mobile top bar varies by page class, and
 * the mobile primary action floats above the bottom bar. Reading a long page takes the mobile chrome
 * away — the bars slide off and the action collapses to its icon while the reader scrolls down, and
 * one scroll up brings all of it back. Two page classes are the exception: a compose screen becomes a
 * full-page sheet — no bottom bar, a close-plus-actions header, and a bottom-to-top entry it plays in
 * reverse when the close control leaves — and a conversation also drops the bottom bar and holds its
 * chrome still, on the ordinary back-plus-scope bar, since it is a place to be in rather than a sheet
 * to close.
 */
export function AppShell({ chrome, children }: { chrome: Chrome; children: ReactNode }) {
    const { props, url } = usePage<PageProps>();
    // The bottom bar is member nav, so the space it reserves goes with it: a guest gets no bar, and
    // reserves only the home-indicator strip the page would otherwise scroll its last row under.
    const member = props.auth.user !== null;
    const compose = Boolean(chrome.compose);
    const bottomNav = member && hasBottomNav(chrome);
    // One listener for the whole chrome, so the bars and the action cannot fall out of step. A form
    // and a conversation keep their chrome (see the flags), and a guest's bar carries their way in,
    // not nav they can bring back.
    const hidden = useScrollDirection({ enabled: member && chromeRecedes(chrome) }) === 'down';
    const { exiting, exit, onAnimationEnd } = useComposeExitState(compose);

    return (
        <ComposeSheetProvider exit={exit}>
            <div
                className={cn(
                    'mx-auto flex min-h-dvh max-w-6xl [--modern-top-offset:calc(3rem+env(safe-area-inset-top))] lg:[--modern-top-offset:0px] xl:max-w-7xl',
                    bottomNav
                        ? // The extra pixel is the bottom bar's top hairline: the top bar draws its own
                          // inside its height, the bottom bar's sits above the row, and both vars mean
                          // the same thing — how much of the screen the bar takes. With no bar the var
                          // is the home-indicator strip alone, so what stands on it (a sheet, a
                          // conversation's composer) stands at the foot of the screen.
                          '[--modern-bottom-offset:calc(3rem+1px+env(safe-area-inset-bottom))] lg:[--modern-bottom-offset:0px]'
                        : '[--modern-bottom-offset:env(safe-area-inset-bottom)] lg:[--modern-bottom-offset:0px]',
                )}
            >
                <LeftNav />
                {/* The key remounts the column on every navigation into or within compose, so the
                    sheet plays its entry each time. A validation POST keeps the URL and so the key,
                    which is what keeps the form's state: a preserveState visit that changed the URL
                    inside compose would break that. */}
                <div
                    key={compose ? url : 'chrome'}
                    // Closed: the column is on its way off the screen, so it stops taking input while
                    // the navigation it is waiting for is still in flight.
                    inert={exiting || undefined}
                    onAnimationEnd={onAnimationEnd}
                    className={cn(
                        'min-w-0 flex-1 pb-[var(--modern-bottom-offset)]',
                        compose && (exiting ? 'max-lg:motion-safe:animate-modern-sheet-out' : 'max-lg:motion-safe:animate-modern-sheet'),
                    )}
                >
                    <TopNav chrome={chrome} hidden={hidden} />
                    {children}
                </div>
                <RightRail />
                <ConfirmDialogHost />
                <UnreadSync />
                <ActionFab chrome={chrome} extended={!hidden} />
                {bottomNav && <BottomNav hidden={hidden} />}
                {/* Zero height in a browser; in a standalone PWA it holds the status-bar area the top bar
                    draws under, so page content does not run beneath the clock once the bar slides off. */}
                <div
                    aria-hidden
                    className="pointer-events-none fixed inset-x-0 top-0 z-30 h-[env(safe-area-inset-top)] bg-background/90 backdrop-blur lg:hidden"
                />
            </div>
        </ComposeSheetProvider>
    );
}
