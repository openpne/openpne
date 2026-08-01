import { cn } from '@/lib/utils';

/**
 * The attention badge the nav lists and the mobile bottom bar share: a count pill that renders
 * nothing while there is nothing to attend to. Pass `label` when the pill is the only thing naming
 * the count; leave it off where the control around it already says ":count unread …" — a second
 * announcement of the same number is what a screen reader would otherwise get.
 */
export function UnreadPill({ count, label, className }: { count: number; label?: string; className?: string }) {
    if (count <= 0) {
        return null;
    }

    return (
        <span
            className={cn(
                'inline-flex h-5 min-w-5 shrink-0 items-center justify-center rounded-full bg-primary px-1.5 text-[11px] font-semibold leading-none text-primary-foreground',
                className,
            )}
            aria-label={label}
            aria-hidden={label === undefined ? true : undefined}
        >
            {count > 99 ? '99+' : count}
        </span>
    );
}
