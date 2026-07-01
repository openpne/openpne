import { Head, Link, usePage } from '@inertiajs/react';
import { Avatar } from '@/components/avatar';
import { Pagination } from '@/components/pagination';
import { formatDateOnly } from '@/lib/date';
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
                {flash.status && <p role="status">{flash.status}</p>}

                <div className="flex items-center justify-between gap-3">
                    <h1 className="min-w-0 text-2xl font-semibold">
                        <Link href={`/m/community/${community.id}`} className="hover:underline">
                            {community.name}
                        </Link>
                        {' — '}
                        {t('Events')}
                    </h1>
                    {canPost && (
                        <Link href={`/m/community/${community.id}/event/new`} className="shrink-0 text-sm hover:underline">
                            {t('Post a new event')}
                        </Link>
                    )}
                </div>

                {events.data.length === 0 ? (
                    <p>{t('No events to show.')}</p>
                ) : (
                    <>
                        <ul className="divide-y">
                            {events.data.map((event) => (
                                <li key={event.id}>
                                    <Link href={`/m/community/event/${event.id}`} className="flex items-start gap-3 py-3 hover:bg-muted/40">
                                        <Avatar id={event.author?.id ?? 0} name={event.author?.name ?? ''} src={event.author?.imageUrl ?? null} size="sm" />
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate font-medium">
                                                {event.name} ({event.commentCount})
                                            </p>
                                            <p className="truncate text-xs text-muted-foreground">
                                                {t('Open date')}: {formatDateOnly(event.openDate)}
                                                {' · '}
                                                {event.author?.name ?? t('Withdrawn member')}
                                            </p>
                                        </div>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                        <Pagination meta={events.meta} />
                    </>
                )}
            </main>
        </>
    );
}
