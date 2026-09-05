import { cn } from '@/lib/utils';

/**
 * The pill never names anything by itself: its digits are hidden, and `label` is a phrase a screen
 * reader reads in place of them. Pass it only where the pill sits inside a control named from its
 * contents; anywhere else the words belong to whatever the count is about.
 */
export function CountPill({ count, label, className }: { count: number; label?: string; className?: string }) {
    if (count <= 0) {
        return null;
    }

    return (
        <span
            className={cn(
                'inline-flex h-5 min-w-5 shrink-0 items-center justify-center rounded-full bg-primary px-1.5 text-[11px] leading-none text-primary-foreground',
                className,
            )}
        >
            {/* Hidden on the digits, never on the span around them: hidden outside, the phrase below
                goes with it and the count is lost from the name with nothing to show for it. */}
            <span aria-hidden>{count > 99 ? '99+' : count}</span>
            {label !== undefined && <span className="sr-only">{label}</span>}
        </span>
    );
}
