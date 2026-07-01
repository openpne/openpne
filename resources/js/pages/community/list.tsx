import { Head, Link, usePage } from '@inertiajs/react';
import { CommunityImage } from '@/components/community-image';
import { Pagination } from '@/components/pagination';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { PaginatedCommunities } from './types';

interface ListProps extends PageProps {
    communities: PaginatedCommunities;
    owner: { id: number; name: string };
    isOwner: boolean;
}

export default function CommunityList() {
    const t = useT();
    const { communities, owner, isOwner } = usePage<ListProps>().props;
    const title = isOwner ? t('My %communities%') : t(":name's %communities%", { name: owner.name });

    return (
        <>
            <Head title={title} />
            <main className="mx-auto max-w-2xl space-y-4 px-4 py-8">
                <h1 className="text-2xl font-semibold">{title}</h1>

                {communities.data.length === 0 ? (
                    <p>{t('No %communities% to show.')}</p>
                ) : (
                    <>
                        <ul className="grid grid-cols-3 gap-4 sm:grid-cols-4">
                            {communities.data.map((community) => (
                                <li key={community.id}>
                                    <Link href={`/m/community/${community.id}`} className="flex flex-col gap-1">
                                        <CommunityImage id={community.id} name={community.name} src={community.imageUrl} className="aspect-square w-full" textClassName="text-2xl" />
                                        <span className="truncate text-sm">{community.name}</span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                        <Pagination meta={communities.meta} />
                    </>
                )}
            </main>
        </>
    );
}
