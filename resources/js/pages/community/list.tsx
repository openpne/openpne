import { Head, Link, usePage } from '@inertiajs/react';
import { BellOff } from 'lucide-react';
import { CommunityImage } from '@/components/community-image';
import { CountPill } from '@/components/count-pill';
import { Pagination } from '@/components/pagination';
import { Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';
import type { MemberRef, PaginatedCommunities } from './types';

/** Per-group talk state for the viewer, keyed by group id. Empty on someone else's list. */
type TalkUnread = Record<string, { count: number; muted: boolean } | undefined>;

interface ListProps extends PageProps {
    groups: PaginatedCommunities;
    owner: MemberRef;
    isOwner: boolean;
    talkUnread: TalkUnread;
}

export default function CommunityList() {
    const t = useT();
    const { groups, owner, isOwner, talkUnread } = usePage<ListProps>().props;
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
                            {groups.data.map((group) => {
                                const talk = talkUnread[String(group.id)];
                                const unread = talk?.count ?? 0;

                                return (
                                    <li key={group.id}>
                                        <Link href={`/groups/${group.id}`} className="flex flex-col gap-1">
                                            <span className="relative block">
                                                <CommunityImage name={group.name} src={group.imageUrl} className="aspect-square w-full" textClassName="text-2xl" decorative />
                                                {/* The whole tile is one link, so the pill can name the count itself
                                                    rather than needing a separate sr-only label. A muted group keeps
                                                    its number but gives up the pill's insistence — it is still there
                                                    to be found, it just stops asking to be looked at. */}
                                                <CountPill
                                                    count={unread}
                                                    label={t(':count unread messages')}
                                                    className={cn(
                                                        'absolute -top-1.5 -right-1.5',
                                                        talk?.muted === true && 'bg-muted text-muted-foreground',
                                                    )}
                                                />
                                            </span>
                                            <span className="flex min-w-0 items-center gap-1">
                                                <span className="truncate text-sm">{group.name}</span>
                                                {talk?.muted === true && (
                                                    <BellOff className="size-3 shrink-0 text-muted-foreground" aria-label={t('Muted')} />
                                                )}
                                            </span>
                                        </Link>
                                    </li>
                                );
                            })}
                        </ul>
                    </Panel>
                    <Pagination meta={groups.meta} />
                </>
            )}
        </>
    );
}
