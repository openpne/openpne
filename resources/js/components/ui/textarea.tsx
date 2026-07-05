import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

/** Token-based multiline input. Set `aria-invalid` to surface the error ring. */
export function Textarea({ className, ...props }: ComponentProps<'textarea'>) {
    return (
        <textarea
            className={cn(
                // text-base on mobile: a <16px control makes iOS Safari auto-zoom on focus, and the
                // zoom persists across Inertia's SPA navigations (page looks cut off on the right).
                'flex min-h-24 w-full rounded-field border border-field-border bg-field px-3 py-2 text-base text-foreground shadow-sm transition-colors placeholder:text-muted-foreground focus-visible:border-ring focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50 aria-[invalid=true]:border-destructive aria-[invalid=true]:ring-2 aria-[invalid=true]:ring-destructive/30 md:text-sm',
                className,
            )}
            {...props}
        />
    );
}
