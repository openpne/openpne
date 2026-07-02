import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

/** Token-based checkbox (native, so it stays Inertia useForm-friendly). `accent-primary` tints it. */
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
