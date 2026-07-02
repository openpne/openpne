import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

/** Token-based text input. Set `aria-invalid` to surface the error ring. */
export function Input({ className, ...props }: ComponentProps<'input'>) {
    return (
        <input
            className={cn(
                'flex min-h-11 w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground shadow-sm transition-colors placeholder:text-muted-foreground focus-visible:border-ring focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40 disabled:cursor-not-allowed disabled:opacity-50 aria-[invalid=true]:border-destructive aria-[invalid=true]:ring-2 aria-[invalid=true]:ring-destructive/30',
                className,
            )}
            {...props}
        />
    );
}
