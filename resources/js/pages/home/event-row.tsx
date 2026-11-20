import { Link } from '@inertiajs/react';
import { participantsPhrase } from '@/lib/count-phrase';
import { useT } from '@/lib/i18n';
import { useDateFormat } from '@/lib/use-date-format';
import type { UpcomingEvent } from './types';

/** The date slot carries the day the event falls on rather than when it was posted. */
export function UpcomingEventRow({ event }: { event: UpcomingEvent }) {
    const t = useT();
    const { civilMonthDay } = useDateFormat();
    const participants = event.participantCount ?? 0;

    return (
        <li className="border-t border-border first:border-t-0">
            <Link href={`/events/${event.id}`} className="group flex min-h-14 items-center gap-2 py-2">
                <time dateTime={event.openDate} className="w-20 shrink-0 text-xs text-foreground">
                    {civilMonthDay(event.openDate, true)}
                </time>
                <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                    <span className="line-clamp-2 break-words font-serif text-base text-foreground group-hover:underline">{event.name}</span>
                    <span className="truncate text-xs text-muted-foreground">
                        {event.group.name}
                        {participants > 0 && ` · ${participantsPhrase(t, participants)}`}
                    </span>
                </span>
            </Link>
        </li>
    );
}
