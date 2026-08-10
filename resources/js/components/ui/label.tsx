import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

/** Token-based form label. */
export function Label({ className, ...props }: ComponentProps<'label'>) {
    return <label className={cn('text-sm text-foreground', className)} {...props} />;
}
