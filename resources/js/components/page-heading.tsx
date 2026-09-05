import type { ReactNode } from 'react';
import { Heading } from '@/components/ui/heading';
import { cn } from '@/lib/utils';

type Props = {
    title: ReactNode;
    action?: ReactNode;
    /** Hub pages: below lg the top bar shows this title, so the row folds away and the h1 stays for
     *  assistive technology alone. */
    fold?: boolean;
    className?: string;
};

/**
 * The folded row is sr-only rather than display-hidden, so it leaves the flow while the h1 stays in
 * the accessibility tree, and the action inside it is hidden outright so its link never takes focus.
 * Scoped to `max-lg` rather than un-hidden at `lg`: Tailwind's un-hide utility resets `margin: 0`,
 * outranking the `:where()` selector `space-y-*` generates.
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
