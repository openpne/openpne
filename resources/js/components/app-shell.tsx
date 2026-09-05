import { usePage } from '@inertiajs/react';
import { type ReactNode, useLayoutEffect, useState } from 'react';
import { ActionFab } from '@/components/action-fab';
import { BottomNav } from '@/components/bottom-nav';
import { ComposeSheetProvider, useComposeExitState } from '@/components/compose/compose-sheet-action';
import { ConfirmDialogHost } from '@/components/confirm-dialog';
import { LeftNav } from '@/components/left-nav';
import { RightRail } from '@/components/right-rail';
import { TopNav } from '@/components/top-nav';
import { UnreadSync } from '@/components/unread-sync';
import { type Chrome, chromeRecedes, hasBottomNav, lookSpec } from '@/lib/member-chrome';
import { useScrollDirection } from '@/lib/use-scroll-direction';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

/**
 * Nav chrome only, around a page that keeps its own <main> and flash
 * (docs/internals/feature-modules.md, "Surface responsibilities").
 */
export function AppShell({ chrome, children }: { chrome: Chrome; children: ReactNode }) {
    const { props, url } = usePage<PageProps>();
    // The bottom bar is member nav, so the space it reserves goes with it: a guest gets no bar, and
    // reserves only the home-indicator strip the page would otherwise scroll its last row under.
    const member = props.auth.user !== null;
    const compose = Boolean(chrome.compose);
    const look = lookSpec(props.look);
    const [composerEngaged, setComposerEngaged] = useState(false);
    const conversationBar = member && look.bottomBarInConversation && Boolean(chrome.conversation);
    const engaged = conversationBar && composerEngaged;
    const bottomNav = member && (hasBottomNav(chrome) || (conversationBar && !engaged));
    // One listener for the whole chrome, so the bars and the action cannot fall out of step.
    const hidden = useScrollDirection({ enabled: member && chromeRecedes(chrome) }) === 'down';
    const { exiting, exit, onAnimationEnd } = useComposeExitState(compose);

    // The body paints --background, so a wrapper here could not recolor what lies behind the shell;
    // cleared on unmount so an admin or auth screen visited next keeps the shipped paper.
    const ground = look.ground === 'unified';
    useLayoutEffect(() => {
        document.documentElement.classList.toggle('unified', ground);

        return () => document.documentElement.classList.remove('unified');
    }, [ground]);

    // The extra pixel is the bottom bar's top hairline; written as whole class names, one per case,
    // because a var built by interpolation is a class Tailwind never sees.
    const bottomOffset = !bottomNav
        ? '[--modern-bottom-offset:env(safe-area-inset-bottom)]'
        : look.bottomBar === 'labeled'
          ? '[--modern-bottom-offset:calc(3.625rem+1px+env(safe-area-inset-bottom))]'
          : '[--modern-bottom-offset:calc(3rem+1px+env(safe-area-inset-bottom))]';
    // The site-color line atop the breadcrumb bar belongs to that bar, so a page's sticky header
    // offsets by it too.
    const topOffset =
        look.topBar === 'breadcrumb'
            ? '[--modern-top-offset:calc(3rem+4px+env(safe-area-inset-top))]'
            : '[--modern-top-offset:calc(3rem+env(safe-area-inset-top))]';
    // Where no desktop bar stands the var still answers for what a sticky page header must clear,
    // the color line included; one exclusive choice, because two `lg:` rules would have equal weight.
    const desktopTopOffset = look.desktopTopBar
        ? undefined
        : look.colorLine
          ? 'lg:[--modern-top-offset:4px]'
          : 'lg:[--modern-top-offset:0px]';

    return (
        <ComposeSheetProvider exit={exit} onComposerEngaged={setComposerEngaged}>
            <div
                className={cn(
                    'mx-auto flex min-h-dvh',
                    // Without the third column the frame is the sidebar plus the content column, so
                    // the pair centers as one block.
                    look.rightRail ? 'max-w-6xl xl:max-w-7xl' : 'max-w-6xl lg:max-w-[58rem]',
                    topOffset,
                    desktopTopOffset,
                    bottomOffset,
                    'lg:[--modern-bottom-offset:0px]',
                )}
            >
                <LeftNav />
                {/* The key remounts the column on every navigation into or within compose, so the
                    sheet plays its entry each time. */}
                {/* A validation POST keeps the URL and so the key, which is what keeps the form's state. */}
                <div
                    key={compose ? url : 'chrome'}
                    // Closed: the column is on its way off the screen, so it stops taking input while
                    // the navigation it is waiting for is still in flight.
                    inert={exiting || undefined}
                    onAnimationEnd={onAnimationEnd}
                    className={cn(
                        'min-w-0 flex-1',
                        // A conversation carries its own foot, so reserving the strip here as well
                        // would hold the bar that much higher.
                        !chrome.conversation && 'pb-[var(--modern-bottom-offset)]',
                        compose && (exiting ? 'max-lg:motion-safe:animate-modern-sheet-out' : 'max-lg:motion-safe:animate-modern-sheet'),
                    )}
                >
                    <TopNav chrome={chrome} hidden={hidden} />
                    {children}
                </div>
                {look.rightRail && <RightRail />}
                <ConfirmDialogHost />
                <UnreadSync />
                <ActionFab chrome={chrome} extended={!hidden} />
                {(bottomNav || conversationBar) && <BottomNav chrome={chrome} hidden={hidden || engaged} />}
                {/* Desk width only: the phone's copy of the line lives inside the breadcrumb bar,
                    whose height counts it. */}
                {look.colorLine && (
                    <div
                        aria-hidden
                        data-testid="site-color-line"
                        className="fixed inset-x-0 top-0 z-30 hidden h-1 lg:block"
                        style={{ backgroundColor: props.snsLogo.color }}
                    />
                )}
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
