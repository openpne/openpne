import { Head, Link, router, usePage } from '@inertiajs/react';
import { Avatar } from '@/components/avatar';
import { useConfirm } from '@/components/confirm-dialog';
import { Pagination } from '@/components/pagination';
import { dangerActionClass } from '@/components/ui/danger-link';
import { List, ListRow, Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';
import type { FriendMember, PaginatedFriends } from './types';

interface ListProps extends PageProps {
    owner: FriendMember;
    isOwner: boolean;
    friends: PaginatedFriends;
}

export default function FriendList() {
    const t = useT();
    const confirm = useConfirm();
    const { owner, isOwner, friends } = usePage<ListProps>().props;
    const title = isOwner ? t('%Friends%') : t(":name's %friends%", { name: owner.name });

    const unlinkFriend = async (id: number, name: string) => {
        if (await confirm({ title: t('Remove :name from your %friends%?', { name }), confirmLabel: t('Remove %friend%'), danger: true })) {
            router.post(`/m/friend/unlink/${id}`);
        }
    };

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
                                        <button type="button" onClick={() => unlinkFriend(friend.id, friend.name)} className={cn(dangerActionClass, 'shrink-0 text-sm')}>
                                            {t('Remove %friend%')}
                                        </button>
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
