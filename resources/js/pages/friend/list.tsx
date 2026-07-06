import { Head, Link, usePage } from '@inertiajs/react';
import { Avatar } from '@/components/avatar';
import { Pagination } from '@/components/pagination';
import { DangerLink } from '@/components/ui/danger-link';
import { List, ListRow, Panel } from '@/components/ui/surface';
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
    const { owner, isOwner, friends } = usePage<ListProps>().props;
    const title = isOwner ? t('%Friends%') : t(":name's %friends%", { name: owner.name });

    return (
        <>
            <Head title={title} />
            {friends.data.length === 0 ? (
                <Panel>
                    <p className="text-sm text-muted-foreground">{t('No %friends% to show.')}</p>
                </Panel>
            ) : (
                <>
                    <Panel flush>
                        <List>
                            {friends.data.map((friend) => (
                                <ListRow key={friend.id}>
                                    <Link href={`/m/member/${friend.id}`} className="flex min-w-0 flex-1 items-center gap-3 text-foreground hover:underline">
                                        <Avatar id={friend.id} name={friend.name} src={friend.imageUrl} size="sm" decorative />
                                        <span className="min-w-0 flex-1 truncate">{friend.name}</span>
                                    </Link>
                                    {isOwner && (
                                        <DangerLink href={`/m/friend/unlink/${friend.id}`} className="shrink-0 text-sm">
                                            {t('Remove %friend%')}
                                        </DangerLink>
                                    )}
                                </ListRow>
                            ))}
                        </List>
                    </Panel>
                    <Pagination meta={friends.meta} />
                </>
            )}
        </>
    );
}
