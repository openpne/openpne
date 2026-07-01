import { Head, Link, usePage } from '@inertiajs/react';
import { Avatar } from '@/components/avatar';
import { Pagination } from '@/components/pagination';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { EventDetail, PaginatedEventParticipants } from '../types';

interface MembersProps extends PageProps {
    event: EventDetail;
    participants: PaginatedEventParticipants;
}

export default function CommunityEventMembers() {
    const t = useT();
    const { event, participants } = usePage<MembersProps>().props;

    return (
        <>
            <Head title={t('Count of Member')} />
            <main className="mx-auto max-w-2xl space-y-4 px-4 py-8">
                <h1 className="text-2xl font-semibold">
                    <Link href={`/m/community/event/${event.id}`} className="hover:underline">
                        {event.name}
                    </Link>
                    {' — '}
                    {t('Count of Member')}
                </h1>

                {participants.data.length === 0 ? (
                    <p>{t('No participants yet.')}</p>
                ) : (
                    <>
                        <ul className="flex flex-wrap gap-4">
                            {participants.data.map((participant) => (
                                <li key={participant.id} className="w-16">
                                    <Link href={`/m/member/${participant.id}`} className="flex flex-col items-center gap-1">
                                        <Avatar id={participant.id} name={participant.name} src={participant.imageUrl} size="lg" />
                                        <span className="w-full truncate text-center text-xs">{participant.name}</span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                        <Pagination meta={participants.meta} />
                    </>
                )}
            </main>
        </>
    );
}
