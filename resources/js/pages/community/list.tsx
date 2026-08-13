import { Head, Link, usePage } from '@inertiajs/react';
import { CommunityImage } from '@/components/community-image';
import { Pagination } from '@/components/pagination';
import { Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { MemberRef, PaginatedCommunities } from './types';

interface ListProps extends PageProps {
    groups: PaginatedCommunities;
    owner: MemberRef;
    isOwner: boolean;
}

export default function CommunityList() {
    const t = useT();
    const { groups, owner, isOwner } = usePage<ListProps>().props;
    const title = isOwner ? t('My %communities%') : t(":name's %communities%", { name: owner.name });

    return (
        <>
            <Head title={title} />
            {groups.data.length === 0 ? (
                <Panel>
                    <p className="text-sm text-muted-foreground">{t('No %communities% to show.')}</p>
                </Panel>
            ) : (
                <>
                    <Panel>
                        <ul className="grid grid-cols-3 gap-4 sm:grid-cols-4">
                            {groups.data.map((group) => (
                                <li key={group.id}>
                                    <Link href={`/groups/${group.id}`} className="flex flex-col gap-1">
                                        <CommunityImage name={group.name} src={group.imageUrl} className="aspect-square w-full" textClassName="text-2xl" decorative />
                                        <span className="truncate text-sm">{group.name}</span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </Panel>
                    <Pagination meta={groups.meta} />
                </>
            )}
        </>
    );
}
