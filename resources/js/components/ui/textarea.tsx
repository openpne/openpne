import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

/** Token-based multiline input. Set `aria-invalid` to surface the error ring. */
export function Textarea({ className, sheet, ...props }: ComponentProps<'textarea'> & { sheet?: boolean }) {
    return (
        <textarea
            className={cn(
                // text-base on mobile: a <16px control makes iOS Safari auto-zoom on focus, and the
                // zoom persists across Inertia's SPA navigations (page looks cut off on the right).
                'flex min-h-24 w-full rounded-field border border-field-border bg-field px-3 py-2 text-base text-foreground shadow-sm transition-colors placeholder:text-muted-foreground focus-visible:border-ring focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50 aria-[invalid=true]:border-destructive aria-[invalid=true]:ring-2 aria-[invalid=true]:ring-destructive/30 md:text-sm',
                // A compose sheet's body below lg: nothing at all around the text. The focus ring goes
                // too — it is the writing surface of the screen, held for the whole session, and a ring
                // painted around it is the box the sheet just removed. The caret is the focus indicator
                // here, as it is in every full-screen composer; the error ring above still fires.
                // The resize grabber goes with the box it used to sit in the corner of: naked, it is a
                // mark floating in the page, and dragging it is not a gesture a phone has anyway.
                sheet &&
                    'max-lg:resize-none max-lg:rounded-none max-lg:border-0 max-lg:bg-transparent max-lg:px-0 max-lg:shadow-none max-lg:focus-visible:ring-0',
                className,
            )}
            {...props}
        />
    );
}
