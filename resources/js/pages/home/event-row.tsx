import { EntryRow } from '@/components/entry-row';
import { CivilDate } from '@/components/timestamp';
import { useT } from '@/lib/i18n';
import type { UpcomingEvent } from './types';

/** The date slot carries the day the event falls on rather than when it was posted. */
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
