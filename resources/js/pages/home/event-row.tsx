import { usePage } from '@inertiajs/react';
import { EntryRow } from '@/components/entry-row';
import { useT } from '@/lib/i18n';
import { useDateFormat } from '@/lib/use-date-format';
import { useSiteDay } from '@/lib/use-site-day';
import type { PageProps } from '@/types';
import type { UpcomingEvent } from './types';

/** The date slot carries the day the event falls on rather than when it was posted. */
export function UpcomingEventRow({ event }: { event: UpcomingEvent }) {
    const t = useT();
    const { civilMonthDay } = useDateFormat();
    const { timezone } = usePage<PageProps>().props;
    // Read for the re-render alone: the date drops its year by the site's year, which turns over at the site's midnight.
    useSiteDay(timezone);

    return (
        <EntryRow
            href={`/events/${event.id}`}
            group={event.group}
            content={event.name}
            bylineNote={t('Event')}
            date={<time dateTime={event.openDate}>{civilMonthDay(event.openDate, true)}</time>}
            participantCount={event.participantCount ?? 0}
        />
    );
}
