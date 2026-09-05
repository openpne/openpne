import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { AppShell } from '@/components/app-shell';
import { MemberFrame } from '@/components/member-frame';
import { type Chrome, resolveChrome } from '@/lib/member-chrome';
import type { PageProps } from '@/types';

/**
 * The chrome registry is resolved here once and handed to both the shell and the frame
 * (docs/internals/feature-modules.md, "Surface responsibilities").
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
