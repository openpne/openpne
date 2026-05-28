import { Head, Link, usePage } from '@inertiajs/react';
import { Pagination } from '@/components/pagination';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { FriendMember, PaginatedFriends } from './types';

interface ListProps extends PageProps {
    owner: FriendMember;
    isOwner: boolean;
    friends: PaginatedFriends;
}

export default function FriendList() {
    const t = useT();
    const { owner, isOwner, friends, flash } = usePage<ListProps>().props;
    const title = isOwner ? t('Friends') : t(":name's friends", { name: owner.name });

    return (
        <>
            <Head title={title} />
            <main className="mx-auto max-w-2xl space-y-4 px-4 py-8">
                <h1 className="text-2xl font-semibold">{title}</h1>

                {flash.status && <p role="status">{flash.status}</p>}
                {flash.error && <p role="alert">{flash.error}</p>}

                {friends.data.length === 0 ? (
                    <p>{t('No friends to show.')}</p>
                ) : (
                    <>
                        <ul className="space-y-2">
                            {friends.data.map((friend) => (
                                <li key={friend.id} className="flex items-center justify-between">
                                    <span>{friend.name}</span>
                                    {isOwner && (
                                        <Link
                                            href={`/m/friend/unlink/${friend.id}`}
                                            className="text-sm text-muted-foreground hover:underline"
                                        >
                                            {t('Unfriend')}
                                        </Link>
                                    )}
                                </li>
                            ))}
                        </ul>
                        <Pagination meta={friends.meta} />
                    </>
                )}
            </main>
        </>
    );
}
