import type { ReactNode } from 'react';
import { AppShell } from '@/components/app-shell';
import { MemberFrame } from '@/components/member-frame';
import type { Chrome } from '@/lib/member-chrome';

/**
 * The default Inertia layout for every non-auth Modern page (wired via createInertiaApp's `layout`
 * option): nav chrome (AppShell) + the page frame (MemberFrame). Inertia passes the page props
 * through; the only prop read here is `chrome` — a page overrides its frame chrome by exporting
 * `Page.layout = (props) => ({ chrome: {…} })`, which Inertia merges into this layout's props.
 */
export function MemberLayout({ chrome, children }: { chrome?: Partial<Chrome>; children?: ReactNode }) {
    return (
        <AppShell>
            <MemberFrame chrome={chrome}>{children}</MemberFrame>
        </AppShell>
    );
}
