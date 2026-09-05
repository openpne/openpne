import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

/** Native rather than a styled primitive, so it binds straight to Inertia's useForm. */
export function Checkbox({ className, ...props }: ComponentProps<'input'>) {
    return (
        <input
            type="checkbox"
            className={cn(
                'size-4 shrink-0 accent-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background',
                className,
            )}
            {...props}
        />
    );
}
