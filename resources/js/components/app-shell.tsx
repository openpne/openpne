import { usePage } from '@inertiajs/react';
import { type CSSProperties, type ReactNode, useEffect, useLayoutEffect, useState } from 'react';
import { ActionFab } from '@/components/action-fab';
import { BottomNav } from '@/components/bottom-nav';
import { ComposeSheetProvider, useComposeExitState } from '@/components/compose/compose-sheet-action';
import { ConfirmDialogHost } from '@/components/confirm-dialog';
import { LeftNav } from '@/components/left-nav';
import { TopNav } from '@/components/top-nav';
import { UnreadSync } from '@/components/unread-sync';
import { carriesFiles, carriesPick, takesText } from '@/lib/file-drop';
import { type Chrome, chromeRecedes, hasBottomNav, hasPlaceBar, headerOf } from '@/lib/member-chrome';
import { useScrollDirection } from '@/lib/use-scroll-direction';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

/**
 * Nav chrome only, around a page that keeps its own <main> and flash
 * (docs/internals/feature-modules.md, "Surface responsibilities").
 */
export function AppShell({ chrome, children }: { chrome: Chrome; children: ReactNode }) {
    const { component, props, url } = usePage<PageProps>();
    // The bottom bar is member nav, so the space it reserves goes with it: a guest gets no bar, and
    // reserves only the home-indicator strip the page would otherwise scroll its last row under.
    const member = props.auth.user !== null;
    const compose = Boolean(chrome.compose);
    const [composerEngaged, setComposerEngaged] = useState(false);
    // The tabs stand in a conversation while it is read and leave once the composer is engaged.
    const conversationBar = member && Boolean(chrome.conversation);
    const engaged = conversationBar && composerEngaged;
    const bottomNav = member && (hasBottomNav(chrome) || (conversationBar && !engaged));
    // One listener for the whole chrome, so the bars and the action cannot fall out of step.
    const hidden = useScrollDirection({ enabled: member && chromeRecedes(chrome) }) === 'down';
    const { exiting, exit, onAnimationEnd } = useComposeExitState(compose);

    // A picture dropped outside any target would otherwise open in the tab, over the draft; a file
    // input keeps its native drop, a field keeps a page link's text, and a target that claimed the
    // drop has already prevented it.
    useEffect(() => {
        const guard = (event: DragEvent) => {
            if (!carriesFiles(event.dataTransfer) || event.defaultPrevented) {
                return;
            }
            const target = event.target instanceof Element ? event.target : null;
            if (target?.closest('input[type="file"]')) {
                return;
            }
            if (!carriesPick(event.dataTransfer) && takesText(target)) {
                return;
            }
            event.preventDefault();
            if (event.type === 'dragover' && event.dataTransfer) {
                event.dataTransfer.dropEffect = 'none';
            }
        };
        window.addEventListener('dragover', guard);
        window.addEventListener('drop', guard);

        return () => {
            window.removeEventListener('dragover', guard);
            window.removeEventListener('drop', guard);
        };
    }, []);

    // The body paints --background, so a wrapper here could not recolor what lies behind the shell;
    // cleared on unmount so an admin or auth screen visited next keeps its own paper.
    useLayoutEffect(() => {
        document.documentElement.classList.add('member-ground');

        return () => document.documentElement.classList.remove('member-ground');
    }, []);

    // The extra pixel is the bottom bar's top hairline; whole class names, because a var built by
    // interpolation is a class Tailwind never sees.
    const bottomOffset = bottomNav
        ? '[--modern-bottom-offset:calc(3.625rem+1px+env(safe-area-inset-bottom))]'
        : '[--modern-bottom-offset:env(safe-area-inset-bottom)]';
    // The place bar's height, hairline included, and only where it renders one: a screen with no bar
    // has nothing for a sticky child to stand below.
    const placeBarOffset = hasPlaceBar(headerOf(chrome, { component: String(component), home: props.modernHome, guest: !member }))
        ? 'lg:[--modern-place-bar:calc(3.5rem+1px)]'
        : undefined;

    return (
        <ComposeSheetProvider exit={exit} onComposerEngaged={setComposerEngaged}>
            <div
                className={cn(
                    'mx-auto flex min-h-dvh',
                    // The frame is the sidebar plus the content column, so the pair centers as one block.
                    'max-w-6xl lg:max-w-[64rem]',
                    // The phone header's height, its 4px site-color line included; at desk width no
                    // bar stands, and the var answers for the line alone.
                    '[--modern-top-offset:calc(3rem+4px+env(safe-area-inset-top))] lg:[--modern-top-offset:4px]',
                    placeBarOffset,
                    bottomOffset,
                    'lg:[--modern-bottom-offset:0px]',
                )}
            >
                <LeftNav />
                {/* The key remounts the column on every navigation into or within compose, so the
                    sheet plays its entry each time. */}
                {/* A validation POST keeps the URL and so the key, which is what keeps the form's state;
                    a preserveState visit that changed the URL inside compose would break that. */}
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
                        !chrome.conversation && 'pb-(--modern-bottom-offset)',
                        compose && (exiting ? 'max-lg:motion-safe:animate-modern-sheet-out' : 'max-lg:motion-safe:animate-modern-sheet'),
                    )}
                >
                    <TopNav chrome={chrome} hidden={hidden} />
                    {children}
                </div>
                <ConfirmDialogHost />
                <UnreadSync />
                <ActionFab chrome={chrome} extended={!hidden} />
                {(bottomNav || conversationBar) && <BottomNav hidden={hidden || engaged} />}
                {/* Desk width only: the phone's copy of the line lives inside the phone header,
                    whose height counts it. */}
                <div
                    aria-hidden
                    data-testid="site-color-line"
                    className="fixed inset-x-0 top-0 z-30 hidden h-1 bg-(--site-color) lg:block"
                    style={{ '--site-color': props.snsLogo.color } as CSSProperties}
                />
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
