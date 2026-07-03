import { Head, usePage } from '@inertiajs/react';
import { FlashMessage } from '@/components/flash-message';
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
    const { owner, isOwner, friends, flash } = usePage<ListProps>().props;
    const title = isOwner ? t('%Friends%') : t(":name's %friends%", { name: owner.name });

    return (
        <>
            <Head title={title} />
            <main className="mx-auto max-w-2xl space-y-4 px-4 py-8">
                <h1 className="text-xl font-semibold text-foreground">{title}</h1>

                {flash.status && <FlashMessage>{flash.status}</FlashMessage>}
                {flash.error && <FlashMessage variant="error">{flash.error}</FlashMessage>}

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
                                        <span className="min-w-0 flex-1 truncate text-foreground">{friend.name}</span>
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
            </main>
        </>
    );
}
