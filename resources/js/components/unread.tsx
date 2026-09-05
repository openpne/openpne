import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * The one place unread is expressed, so a second list cannot spell the weight differently
 * (docs/internals/typography.md, "Typography: emphasis on the Modern surface"). The dot and its
 * announcement are separate: where a row's link covers only the subject, a sibling dot is not part
 * of that name, so the announcement goes inside the link.
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
