import { Head, usePage } from '@inertiajs/react';
import { Avatar } from '@/components/avatar';
import { EntryRow } from '@/components/entry-row';
import { Pagination } from '@/components/pagination';
import { List, Panel } from '@/components/ui/surface';
import { formatDate } from '@/lib/date';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { CommunitySummary, PaginatedEvents } from '../types';

interface IndexProps extends PageProps {
    community: CommunitySummary;
    events: PaginatedEvents;
    canPost: boolean;
}

export default function CommunityEventIndex() {
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
                                    href={`/m/community/event/${event.id}`}
                                    leading={<Avatar id={event.author?.id ?? 0} name={event.author?.name ?? ''} src={event.author?.imageUrl ?? null} size="sm" decorative />}
                                    title={event.name}
                                    meta={[event.author?.name ?? t('Withdrawn member'), `${t('Open date')}: ${formatDate(event.openDate)}`]}
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
