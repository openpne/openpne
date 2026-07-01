import { Head, Link, usePage } from '@inertiajs/react';
import { Avatar } from '@/components/avatar';
import { Pagination } from '@/components/pagination';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { CommunitySummary, PaginatedCommunityMembers } from './types';

interface MembersProps extends PageProps {
    community: CommunitySummary;
    members: PaginatedCommunityMembers;
}

export default function CommunityMembers() {
    const t = useT();
    const { community, members } = usePage<MembersProps>().props;

    return (
        <>
            <Head title={t('Members')} />
            <main className="mx-auto max-w-2xl space-y-4 px-4 py-8">
                <h1 className="text-2xl font-semibold">
                    <Link href={`/m/community/${community.id}`} className="hover:underline">
                        {community.name}
                    </Link>
                    {' — '}
                    {t('Members')}
                </h1>

                <ul className="grid grid-cols-3 gap-4 sm:grid-cols-4">
                    {members.data.map((member) => (
                        <li key={member.id}>
                            <Link href={`/m/member/${member.id}`} className="flex flex-col items-center gap-1">
                                <Avatar id={member.id} name={member.name} src={member.imageUrl} size="lg" />
                                <span className="w-full truncate text-center text-sm">{member.name}</span>
                            </Link>
                        </li>
                    ))}
                </ul>
                <Pagination meta={members.meta} />
            </main>
        </>
    );
}
