import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * Unread — one of the two jobs font weight still has on the Modern surface, the other being naming a
 * region. The class that carries it lives here rather than at each list that needs it: a literal
 * spelled out at the call site is a literal the next list can spell differently, which is how message
 * boxes ended up on 600 and notifications on 500, with only one of the two also dimming its read rows.
 *
 * Weight alone, and only weight: read text keeps its color, because dimming it says "already read"
 * and "less important" at once, and the second is not true. The dot is the redundant channel, for
 * anyone the weight step does not reach.
 *
 * The dot and its announcement are separate on purpose. Where the row's link wraps everything, one
 * element could do both; where the link covers only the subject (a message box row, whose stretched
 * link is named by the subject alone), a sibling dot is not part of that name and a reader tabbing to
 * the link never hears the state. So {@link UnreadDot} is decorative and {@link UnreadLabel} goes
 * inside the link, where the state belongs to the thing being opened.
 */

/** Append to a row's own classes: `cn('truncate text-foreground', unreadTextClass(unread))`. */
export function unreadTextClass(unread: boolean): string | undefined {
    return unread ? 'font-semibold' : undefined;
}

/** Announces the state. Place inside the link that opens the entry — see the note above. */
export function UnreadLabel() {
    const t = useT();

    return <span className="sr-only">{t('Unread')}</span>;
}

/** The marker at the row's trailing edge. Decorative — pair with {@link UnreadLabel}. */
export function UnreadDot({ className }: { className?: string }) {
    return <span aria-hidden className={cn('size-2 shrink-0 rounded-full bg-selected', className)} />;
}
