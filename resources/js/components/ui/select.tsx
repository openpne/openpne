import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

/**
 * Token-based native select (keeps the platform picker + Inertia useForm compatibility; a Radix
 * combobox is heavier and unnecessary for these short option lists).
 */
export function Select({ className, ...props }: ComponentProps<'select'>) {
    return (
        <select
            className={cn(
                'flex min-h-11 w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground shadow-sm transition-colors focus-visible:border-ring focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40 disabled:cursor-not-allowed disabled:opacity-50 aria-[invalid=true]:border-destructive',
                className,
            )}
            {...props}
        />
    );
}
