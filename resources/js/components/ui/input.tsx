import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

/** Token-based text input. Set `aria-invalid` to surface the error ring. */
export function Input({ className, sheet, ...props }: ComponentProps<'input'> & { sheet?: boolean }) {
    return (
        <input
            className={cn(
                // text-base on mobile: a <16px control makes iOS Safari auto-zoom on focus, and the
                // zoom persists across Inertia's SPA navigations (page looks cut off on the right).
                'flex min-h-11 w-full rounded-field border border-field-border bg-field px-3 py-2 text-base text-foreground shadow-sm transition-colors placeholder:text-muted-foreground focus-visible:border-ring focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50 aria-[invalid=true]:border-destructive aria-[invalid=true]:ring-2 aria-[invalid=true]:ring-destructive/30 md:text-sm',
                // A compose sheet's title/subject line below lg: the box goes, one hairline stays under
                // the text (the Gmail subject row). The focus ring goes with the box — the hairline
                // itself takes the ring color from `focus-visible:border-ring` above, which is what
                // keeps the focused field visible without redrawing the box it just lost.
                sheet &&
                    'max-lg:rounded-none max-lg:border-x-0 max-lg:border-t-0 max-lg:border-b-border max-lg:bg-transparent max-lg:px-0 max-lg:shadow-none max-lg:focus-visible:ring-0',
                className,
            )}
            {...props}
        />
    );
}
