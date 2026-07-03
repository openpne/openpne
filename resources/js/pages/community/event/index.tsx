import { Head, Link, usePage } from '@inertiajs/react';
import { Avatar } from '@/components/avatar';
import { EntryRow } from '@/components/entry-row';
import { FlashMessage } from '@/components/flash-message';
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
    const { community, events, canPost, flash } = usePage<IndexProps>().props;

    return (
        <>
            <Head title={t('Events')} />
            <main className="mx-auto max-w-2xl space-y-4 px-4 py-8">
                <div className="flex items-center justify-between gap-3">
                    <h1 className="min-w-0 text-2xl font-semibold">
                        <Link href={`/m/community/${community.id}`} className="hover:underline">
                            {community.name}
                        </Link>
                        {' — '}
                        {t('Events')}
                    </h1>
                    {canPost && (
                        <Link href={`/m/community/${community.id}/event/new`} className="shrink-0 text-sm text-link hover:underline">
                            {t('Post a new event')}
                        </Link>
                    )}
                </div>

                {flash.status && <FlashMessage>{flash.status}</FlashMessage>}

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
                                    />
                                ))}
                            </List>
                        </Panel>
                        <Pagination meta={events.meta} />
                    </>
                )}
            </main>
        </>
    );
}
