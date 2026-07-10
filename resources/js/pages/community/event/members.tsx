import { Head, Link, usePage } from '@inertiajs/react';
import { Avatar } from '@/components/avatar';
import { Pagination } from '@/components/pagination';
import { Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { PaginatedEventParticipants } from '../types';

interface MembersProps extends PageProps {
    participants: PaginatedEventParticipants;
}

export default function CommunityEventMembers() {
    const t = useT();
    const { participants } = usePage<MembersProps>().props;

    return (
        <>
            <Head title={t('Count of Member')} />

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
                                    <Link href={`/member/${participant.id}`} className="flex flex-col items-center gap-1">
                                        <Avatar id={participant.id} name={participant.name} src={participant.imageUrl} color={participant.avatarColor} size="lg" decorative />
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
