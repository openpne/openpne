import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { ActionFab } from '@/components/action-fab';
import { BottomNav } from '@/components/bottom-nav';
import { ConfirmDialogHost } from '@/components/confirm-dialog';
import { LeftNav } from '@/components/left-nav';
import { RightRail } from '@/components/right-rail';
import { TopNav } from '@/components/top-nav';
import { UnreadSync } from '@/components/unread-sync';
import type { Chrome } from '@/lib/member-chrome';
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
 * the mobile primary action floats above the bottom bar.
 */
export function AppShell({ chrome, children }: { chrome: Chrome; children: ReactNode }) {
    // The bottom bar is member nav, so the space it reserves goes with it: a guest gets no bar, and
    // reserves only the home-indicator strip the page would otherwise scroll its last row under.
    const member = usePage<PageProps>().props.auth.user !== null;

    return (
        <div
            className={cn(
                'mx-auto flex min-h-dvh max-w-6xl [--modern-top-offset:calc(3.5rem+env(safe-area-inset-top))] lg:[--modern-top-offset:0px] xl:max-w-7xl',
                member
                    ? '[--modern-bottom-offset:calc(3.5rem+env(safe-area-inset-bottom))] lg:[--modern-bottom-offset:0px]'
                    : '[--modern-bottom-offset:env(safe-area-inset-bottom)]',
            )}
        >
            <LeftNav />
            <div className="min-w-0 flex-1 pb-[var(--modern-bottom-offset)]">
                <TopNav chrome={chrome} />
                {children}
            </div>
            <RightRail />
            <ConfirmDialogHost />
            <UnreadSync />
            <ActionFab chrome={chrome} />
            <BottomNav />
        </div>
    );
}
