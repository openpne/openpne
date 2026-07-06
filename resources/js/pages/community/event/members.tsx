import { Head, Link, usePage } from '@inertiajs/react';
import { Avatar } from '@/components/avatar';
import { Pagination } from '@/components/pagination';
import { Panel } from '@/components/ui/surface';
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
            <h1 className="break-words text-xl font-semibold text-foreground">
                <Link href={`/m/community/event/${event.id}`} className="hover:underline">
                    {event.name}
                </Link>
                {' — '}
                {t('Count of Member')}
            </h1>

            {participants.data.length === 0 ? (
                <Panel>
                    <p className="text-sm text-muted-foreground">{t('No participants yet.')}</p>
                </Panel>
            ) : (
                <>
                    <Panel>
                        <ul className="flex flex-wrap gap-4">
                            {participants.data.map((participant) => (
                                <li key={participant.id} className="w-16">
                                    <Link href={`/m/member/${participant.id}`} className="flex flex-col items-center gap-1">
                                        <Avatar id={participant.id} name={participant.name} src={participant.imageUrl} size="lg" decorative />
                                        <span className="w-full truncate text-center text-xs">{participant.name}</span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </Panel>
                    <Pagination meta={participants.meta} />
                </>
            )}
        </>
    );
}
