import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';

type Props = {
    /** Back-link target; omit to hide the back link. */
    backHref?: string;
    backLabel?: string;
    title: string;
    /** Trailing sub-actions (tabs, menu button). */
    right?: ReactNode;
};

/**
 * Sticky sub-header for a Modern page. Its `top` reads the `--modern-top-offset` CSS variable the
 * app shell sets (TopNav height + safe-area inset), falling back to 0 so it works outside the shell.
 */
export function PageHeader({ backHref, backLabel, title, right }: Props) {
    return (
        <div
            className="sticky z-10 border-b border-border bg-background/80 backdrop-blur"
            style={{ top: 'var(--modern-top-offset, 0px)' }}
        >
            <div className="mx-auto flex min-h-11 max-w-2xl items-center gap-3 px-4 py-2">
                {backHref && (
                    <Link
                        href={backHref}
                        className="-ml-2 inline-flex min-h-11 items-center px-2 text-sm text-muted-foreground hover:text-foreground"
                    >
                        ← {backLabel ?? ''}
                    </Link>
                )}
                <h1 className="flex-1 truncate text-sm font-semibold">{title}</h1>
                {right}
            </div>
        </div>
    );
}
