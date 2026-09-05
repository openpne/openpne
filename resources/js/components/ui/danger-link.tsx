import { Link } from '@inertiajs/react';
import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

/**
 * For actions that irreversibly remove content or a relationship (delete, withdraw, unfriend); a
 * non-destructive undo such as unblock stays a text link.
 */
export const dangerActionClass = 'rounded-md text-destructive outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring';

export function DangerLink({ className, ...props }: ComponentProps<typeof Link>) {
    return <Link className={cn(dangerActionClass, className)} {...props} />;
}
