import { Head, usePage } from '@inertiajs/react';
import { EntryRow } from '@/components/entry-row';
import { Pagination } from '@/components/pagination';
import { CivilDate, Timestamp } from '@/components/timestamp';
import { List, Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { CommunitySummary, PaginatedEvents } from '@/pages/community/types';

interface IndexProps extends PageProps {
    group: CommunitySummary;
    events: PaginatedEvents;
    canPost: boolean;
}

export default function GroupEventIndex() {
    const t = useT();
    const { events } = usePage<IndexProps>().props;

    return (
        <>
            <Head title={t('Events')} />
            {events.data.length === 0 ? (
                <Panel>
                    <p className="text-sm text-muted-foreground">{t('No events to show.')}</p>
                </Panel>
            ) : (
                <>
                    <Panel flush>
                        <List>
                            {events.data.map((event) => (
                                <EntryRow
                                    key={event.id}
                                    href={`/events/${event.id}`}
                                    author={event.author}
                                    content={event.name}
                                    date={
                                        <>
                                            {t('Open date')}: <CivilDate value={event.openDate} weekday />
                                            {/* The board sorts on this stamp, but a phone-wide row has room for the open date alone. */}
                                            <span className="hidden sm:inline">
                                                {' '}&middot; {event.commentCount > 0 ? t('Last comment') : t('Posted')}: <Timestamp at={event.bumpedAt} preset="listStamp" />
                                            </span>
                                        </>
                                    }
                                    commentCount={event.commentCount}
                                    participantCount={event.participantCount}
                                />
                            ))}
                        </List>
                    </Panel>
                    <Pagination meta={events.meta} />
                </>
            )}
        </>
    );
}
