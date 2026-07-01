import type { ReactNode } from 'react';
import { usePage } from '@inertiajs/react';
import { ConfirmDialogHost } from '@/components/confirm-dialog';
import { FlashMessage } from '@/components/flash-message';
import { LeftNav } from '@/components/left-nav';
import { TopNav } from '@/components/top-nav';
import type { PageProps } from '@/types';

/**
 * Modern app shell: a desktop sidebar + mobile top bar around a centered main column, wrapping every
 * Modern page via the persistent layout set in app.tsx. `--modern-top-offset` lets a page's sticky
 * PageHeader sit below the mobile top bar (0 on desktop, where the bar is hidden). Central flash is
 * rendered here so pages don't each repeat it.
 */
export function AppShell({ children }: { children: ReactNode }) {
    const { flash } = usePage<PageProps>().props;

    return (
        <div className="mx-auto flex min-h-dvh max-w-6xl [--modern-top-offset:calc(3.5rem+env(safe-area-inset-top))] lg:[--modern-top-offset:0px]">
            <LeftNav />
            <div className="min-w-0 flex-1">
                <TopNav />
                <main className="mx-auto max-w-2xl space-y-4 px-4 py-4 pb-24 lg:pb-6">
                    {flash.status && <FlashMessage variant="success">{flash.status}</FlashMessage>}
                    {flash.error && <FlashMessage variant="error">{flash.error}</FlashMessage>}
                    {children}
                </main>
            </div>
            <ConfirmDialogHost />
        </div>
    );
}
