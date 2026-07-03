import { Link } from '@inertiajs/react';
import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

/**
 * Text-action treatment for destructive operations — ones that irreversibly remove content or a
 * relationship (delete, withdraw, unfriend). Non-destructive undo actions (e.g. unblock, which
 * restores access) stay text-link. Shared by links (DangerLink) and plain <button> actions.
 */
export const dangerActionClass = 'rounded-md text-destructive outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring';

/** An Inertia Link to a destructive action (typically a delete-confirm page). */
export function DangerLink({ className, ...props }: ComponentProps<typeof Link>) {
    return <Link className={cn(dangerActionClass, className)} {...props} />;
}
