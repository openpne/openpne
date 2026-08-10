import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * Everything that says "unread", in one place. Unread is one of the two jobs font weight still has
 * on the Modern surface (the other is naming a region), so the class that carries it lives here
 * rather than at each list that needs it — a literal spelled out at the call site is a literal the
 * next list can spell differently, which is how message boxes ended up on 600 and notifications on
 * 500, with only one of the two also dimming the read rows.
 *
 * Weight alone, and only weight: read text keeps its color, because dimming it says "already read"
 * and "less important" at once, and the second is not true. The dot is the redundant channel, for
 * anyone the weight step does not reach.
 */

/** Append to a row's own classes: `cn('truncate text-foreground', unreadTextClass(unread))`. */
export function unreadTextClass(unread: boolean): string | undefined {
    return unread ? 'font-semibold' : undefined;
}

/** The marker at the row's trailing edge. Decorative on its own — the label inside names the state. */
export function UnreadDot({ className }: { className?: string }) {
    const t = useT();

    return (
        <span className={cn('size-2 shrink-0 rounded-full bg-selected', className)}>
            <span className="sr-only">{t('Unread')}</span>
        </span>
    );
}

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
