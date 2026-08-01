import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { BottomNav } from '@/components/bottom-nav';
import { ConfirmDialogHost } from '@/components/confirm-dialog';
import { LeftNav } from '@/components/left-nav';
import { PostFab } from '@/components/post-fab';
import { RightRail } from '@/components/right-rail';
import { TopNav } from '@/components/top-nav';
import { UnreadSync } from '@/components/unread-sync';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

/**
 * Modern app shell: a desktop sidebar + mobile top and bottom bars around the page, plus a right rail
 * at xl+. The shell is nav chrome only — each page keeps its own <main> and flash, so wrapping an
 * existing page adds navigation without a nested <main> or duplicate flash. `--modern-top-offset` lets
 * a page's sticky header sit below the mobile top bar, `--modern-bottom-offset` keeps fixed/scrolled
 * content clear of the bottom bar (both 0 on desktop, where the bars are hidden). The frame widens at
 * xl to seat the third column without squeezing the centered page content.
 */
export function AppShell({ children }: { children: ReactNode }) {
    // The bottom bar is member nav, so the space it reserves goes with it: a guest gets no bar.
    const member = usePage<PageProps>().props.auth.user !== null;

    return (
        <div
            className={cn(
                'mx-auto flex min-h-dvh max-w-6xl [--modern-top-offset:calc(3.5rem+env(safe-area-inset-top))] lg:[--modern-top-offset:0px] xl:max-w-7xl',
                member
                    ? '[--modern-bottom-offset:calc(3.5rem+env(safe-area-inset-bottom))] lg:[--modern-bottom-offset:0px]'
                    : '[--modern-bottom-offset:0px]',
            )}
        >
            <LeftNav />
            <div className="min-w-0 flex-1 pb-[var(--modern-bottom-offset)]">
                <TopNav />
                {children}
            </div>
            <RightRail />
            <ConfirmDialogHost />
            <UnreadSync />
            <PostFab />
            <BottomNav />
        </div>
    );
}
