import { Head, usePage } from '@inertiajs/react';
import { MemberTile } from '@/components/member-tile';
import { Pagination } from '@/components/pagination';
import { Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { PaginatedCommunityMembers } from './types';

interface MembersProps extends PageProps {
    members: PaginatedCommunityMembers;
}

export default function CommunityMembers() {
    const t = useT();
    const { members } = usePage<MembersProps>().props;

    return (
        <>
            <Head title={t('Members')} />

            <Panel>
                <ul className="grid grid-cols-3 gap-4 sm:grid-cols-4">
                    {members.data.map((member) => (
                        <li key={member.id}>
                            <MemberTile member={member} nameSize="sm" />
                        </li>
                    ))}
                </ul>
            </Panel>
            <Pagination meta={members.meta} />
        </>
    );
}
