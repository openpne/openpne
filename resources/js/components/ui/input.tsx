import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

/** Token-based text input. Set `aria-invalid` to surface the error ring. */
export function Input({ className, ...props }: ComponentProps<'input'>) {
    return (
        <input
            className={cn(
                // text-base on mobile: a <16px control makes iOS Safari auto-zoom on focus, and the
                // zoom persists across Inertia's SPA navigations (page looks cut off on the right).
                // min-w-0 so `w-full` is the last word on the width: the date/time widgets carry an
                // intrinsic minimum of their own, and an engine that lets it win pushes the field past
                // its column and scrolls the whole page sideways.
                'flex min-h-11 w-full min-w-0 rounded-field border border-field-border bg-field px-3 py-2 text-base text-foreground shadow-sm transition-colors placeholder:text-muted-foreground focus-visible:border-ring focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50 aria-[invalid=true]:border-destructive aria-[invalid=true]:ring-2 aria-[invalid=true]:ring-destructive/30 md:text-sm',
                className,
            )}
            {...props}
        />
    );
}
