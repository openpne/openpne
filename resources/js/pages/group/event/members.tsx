import { Head, usePage } from '@inertiajs/react';
import { MemberTile } from '@/components/member-tile';
import { Pagination } from '@/components/pagination';
import { Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { PaginatedEventParticipants } from '@/pages/community/types';

interface MembersProps extends PageProps {
    participants: PaginatedEventParticipants;
}

export default function GroupEventMembers() {
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
                                    <MemberTile member={participant} />
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
