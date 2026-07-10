import { Head, Link, usePage } from '@inertiajs/react';
import { Avatar } from '@/components/avatar';
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
                            <Link href={`/m/member/${member.id}`} className="flex flex-col items-center gap-1">
                                <Avatar id={member.id} name={member.name} src={member.imageUrl} color={member.avatarColor} size="lg" decorative />
                                <span className="w-full truncate text-center text-sm">{member.name}</span>
                            </Link>
                        </li>
                    ))}
                </ul>
            </Panel>
            <Pagination meta={members.meta} />
        </>
    );
}
