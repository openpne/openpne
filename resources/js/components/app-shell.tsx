import type { ReactNode } from 'react';
import { ConfirmDialogHost } from '@/components/confirm-dialog';
import { LeftNav } from '@/components/left-nav';
import { TopNav } from '@/components/top-nav';

/**
 * Modern app shell: a desktop sidebar + mobile top bar around the page. The shell is nav chrome only
 * — each page keeps its own <main> and flash, so wrapping an existing page adds navigation without a
 * nested <main> or duplicate flash. `--modern-top-offset` lets a page's sticky PageHeader sit below
 * the mobile top bar (0 on desktop, where the bar is hidden).
 */
export function AppShell({ children }: { children: ReactNode }) {
    return (
        <div className="mx-auto flex min-h-dvh max-w-6xl [--modern-top-offset:calc(3.5rem+env(safe-area-inset-top))] lg:[--modern-top-offset:0px]">
            <LeftNav />
            <div className="min-w-0 flex-1">
                <TopNav />
                {children}
            </div>
            <ConfirmDialogHost />
        </div>
    );
}
