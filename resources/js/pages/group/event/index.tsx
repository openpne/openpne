import { Head, usePage } from '@inertiajs/react';
import { EntryRow } from '@/components/entry-row';
import { Pagination } from '@/components/pagination';
import { CivilDate } from '@/components/timestamp';
import { EventBoardStamp } from '@/pages/community/board-stamp';
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
                                            <EventBoardStamp commentCount={event.commentCount} bumpedAt={event.bumpedAt} />
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
