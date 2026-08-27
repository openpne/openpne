import { EntryRow } from '@/components/entry-row';
import { CivilDate } from '@/components/timestamp';
import { useT } from '@/lib/i18n';
import type { UpcomingEvent } from './types';

/**
 * An event still ahead, as a list row. The date slot carries the day it falls on rather than when it
 * was posted — an announcement is read for when it is, and the posting time is not what the reader
 * is placing in their week. The group is the byline subject, as on every board row.
 */
export function UpcomingEventRow({ event }: { event: UpcomingEvent }) {
    const t = useT();

    return (
        <EntryRow
            href={`/events/${event.id}`}
            group={event.group}
            content={event.name}
            bylineNote={t('Event')}
            date={<CivilDate value={event.openDate} weekday />}
            participantCount={event.participantCount ?? 0}
        />
    );
}
