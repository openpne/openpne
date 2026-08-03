import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { AppShell } from '@/components/app-shell';
import { MemberFrame } from '@/components/member-frame';
import { type Chrome, resolveChrome } from '@/lib/member-chrome';
import type { PageProps } from '@/types';

/**
 * The default Inertia layout for every non-auth Modern page (wired via createInertiaApp's `layout`
 * option): nav chrome (AppShell) + the page frame (MemberFrame). The chrome registry is resolved here,
 * once, and handed to both — the nav chrome varies by page class (the mobile top bar) from the same
 * object the frame builds its header from. Inertia passes the page props through; the only prop read
 * here is `chrome` — a page overrides its chrome by exporting
 * `Page.layout = (props) => ({ chrome: {…} })`, which Inertia merges into this layout's props.
 */
export function MemberLayout({ chrome: override, children }: { chrome?: Partial<Chrome>; children?: ReactNode }) {
    const { component, props } = usePage<PageProps>();
    const chrome = resolveChrome(String(component), props, override);

    return (
        <AppShell chrome={chrome}>
            <MemberFrame chrome={chrome}>{children}</MemberFrame>
        </AppShell>
    );
}
