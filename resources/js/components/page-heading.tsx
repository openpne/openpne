import type { ReactNode } from 'react';
import { Heading } from '@/components/ui/heading';
import { cn } from '@/lib/utils';

type Props = {
    title: ReactNode;
    /** The screen's primary create/start/request/send action, right-aligned. Omit when the screen has none. */
    action?: ReactNode;
    /** Hub pages: below lg the top bar shows this title, so the row folds away and the h1 stays for
     *  assistive technology alone. */
    fold?: boolean;
    className?: string;
};

/**
 * Canonical page heading: the page title (h1) with an optional right-aligned primary action. Every
 * Modern index/list page uses this so a screen's primary action is always the button at the top-right,
 * findable without per-screen learning. Overflow is encoded here once: the title owns `min-w-0` +
 * wrapping and the action `shrink-0`, so a long community/member name never clips or squeezes it.
 *
 * Below lg the action lives in the floating ActionFab instead, on every page — a screen has one
 * primary action, and at that width it is the one place it sits. The folded ROW is sr-only, not
 * display-hidden: absolute positioning takes it out of the flow, so it neither shows nor spends a
 * `space-y` gap, while the h1 inside stays in the accessibility tree. The action is display-hidden
 * separately so its link never takes keyboard focus while invisible.
 *
 * The fold is scoped to `max-lg` rather than un-hidden at `lg`: Tailwind's un-hide utility resets
 * `margin: 0`, which outranks the zero-specificity `:where()` selector `space-y-*` generates, so the
 * frame's gap below this row would vanish at exactly the widths where the row is visible.
 */
export function PageHeading({ title, action, fold, className }: Props) {
    return (
        <div
            className={cn(
                fold ? 'max-lg:sr-only lg:flex lg:min-h-11' : 'flex min-h-11',
                'items-center justify-between gap-3',
                className,
            )}
        >
            <Heading variant="page" className="min-w-0">
                {title}
            </Heading>
            {action && <div className="hidden shrink-0 lg:block">{action}</div>}
        </div>
    );
}
