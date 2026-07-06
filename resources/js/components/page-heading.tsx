import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

type Props = {
    title: ReactNode;
    /** The screen's primary create/start/request/send action, right-aligned. Omit when the screen has none. */
    action?: ReactNode;
    className?: string;
};

/**
 * Canonical page heading: the page title (h1) with an optional right-aligned primary action. Every
 * Modern index/list page uses this so a screen's primary action is always the button at the top-right,
 * findable without per-screen learning. Overflow is encoded here once: the title owns `min-w-0` +
 * wrapping and the action `shrink-0`, so a long community/member name never clips or squeezes it.
 */
export function PageHeading({ title, action, className }: Props) {
    return (
        <div className={cn('flex min-h-11 items-center justify-between gap-3', className)}>
            <h1 className="min-w-0 break-words text-xl font-semibold text-foreground">{title}</h1>
            {action && <div className="shrink-0">{action}</div>}
        </div>
    );
}
