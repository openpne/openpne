import { Head, Link, router, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { Avatar } from '@/components/avatar';
import { CommunityImage } from '@/components/community-image';
import { useConfirm } from '@/components/confirm-dialog';
import { EntryRow } from '@/components/entry-row';
import { Heading } from '@/components/ui/heading';
import { UserText } from '@/components/user-text';
import { ActionLink } from '@/components/ui/action-link';
import { Button } from '@/components/ui/button';
import { List, Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import { useDateFormat } from '@/lib/use-date-format';
import type { PageProps } from '@/types';
import type { CommunityDetail, CommunityMemberRow, CommunityRoleSlug, EventSummary, TopicSummary } from './types';

interface ShowProps extends PageProps {
    community: CommunityDetail;
    viewerRole: CommunityRoleSlug | null;
    isPending: boolean;
    isTransferNominee: boolean; // the viewer is the pending admin-transfer nominee → the accept/reject banner
    canManage: boolean;
    canJoin: boolean;
    canLeave: boolean;
    members: CommunityMemberRow[];
    recentTopics: TopicSummary[] | null; // null → the viewer may not read the boards
    canPostTopic: boolean;
    recentEvents: EventSummary[] | null;
    canPostEvent: boolean;
}

export default function CommunityShow() {
    const t = useT();
    const date = useDateFormat();
    const confirm = useConfirm();
    const {
        community, viewerRole, canManage, isPending, isTransferNominee, canJoin, canLeave, members,
        recentTopics, canPostTopic, recentEvents, canPostEvent,
    } = usePage<ShowProps>().props;

    const join = () => router.post('/community/join', { id: community.id });
    const leave = async () => {
        if (await confirm({ title: t('Leave this %community%?'), confirmLabel: t('Leave'), danger: true })) {
            router.post('/community/quit', { id: community.id });
        }
    };

    return (
        <>
            <Head title={community.name} />

            {isTransferNominee && (
                <Panel bodyClassName="space-y-3">
                    <p className="text-sm">{t('The administrator of this %community% asks you to take over the administration.')}</p>
                    <div className="flex gap-3">
                        <Button type="button" onClick={() => router.post('/community/member/acceptTransfer', { id: community.id })}>
                            {t('Accept')}
                        </Button>
                        <Button type="button" variant="secondary" onClick={() => router.post('/community/member/rejectTransfer', { id: community.id })}>
                            {t('Decline')}
                        </Button>
                    </div>
                </Panel>
            )}

            <Panel bodyClassName="space-y-4">
                <div className="flex items-start gap-4">
                    <CommunityImage name={community.name} src={community.imageUrl} className="size-20" textClassName="text-2xl" />
                    <div className="min-w-0 flex-1">
                        <Heading variant="page">{community.name}</Heading>
                        {community.category && <p className="text-sm text-muted-foreground">{community.category.name}</p>}
                        <Link href={`/community/member/list?id=${community.id}`} className="text-sm text-link hover:underline">
                            {t(':count members', { count: community.memberCount })}
                        </Link>
                    </div>
                </div>

                {isPending && <p className="text-sm text-muted-foreground">{t('Your join request is awaiting approval.')}</p>}

                {(canJoin || canLeave) && (
                    <div className="flex gap-3">
                        {canJoin && (
                            <Button type="button" onClick={join}>
                                {community.registerPolicy === 'approval' ? t('Request to join') : t('Join')}
                            </Button>
                        )}
                        {canLeave && (
                            <Button type="button" variant="secondary" onClick={leave}>
                                {t('Leave')}
                            </Button>
                        )}
                    </div>
                )}

                {canManage && (
                    <div className="flex gap-4 text-sm">
                        <Link href={`/community/edit?id=${community.id}`} className="text-link hover:underline">
                            {t('Edit %community%')}
                        </Link>
                        <Link href={`/community/member/manage/${community.id}`} className="text-link hover:underline">
                            {t('Management member')}
                        </Link>
                        {viewerRole === 'admin' && (
                            <Link href={`/community/member/pending?id=${community.id}`} className="text-link hover:underline">
                                {t('Pending members')}
                            </Link>
                        )}
                    </div>
                )}

                {community.description && (
                    <div className="whitespace-pre-wrap break-words">
                        <UserText text={community.description} />
                    </div>
                )}
            </Panel>

            {recentTopics !== null && (
                <Panel
                    flush
                    title={t('Recent %topics%')}
                    right={
                        canPostTopic && (
                            <ActionLink href={`/communityTopic/new/${community.id}`} variant="outline" size="sm">
                                <Plus className="size-4" strokeWidth={2.25} aria-hidden />
                                {t('Create a %topic%')}
                            </ActionLink>
                        )
                    }
                >
                    {recentTopics.length === 0 ? (
                        <p className="px-4 py-4 text-sm text-muted-foreground sm:px-5">{t('No %topics% to show.')}</p>
                    ) : (
                        <List>
                            {recentTopics.map((topic) => (
                                <EntryRow
                                    key={topic.id}
                                    href={`/communityTopic/${topic.id}`}
                                    author={topic.author}
                                    content={topic.name}
                                    date={date.instantDate(topic.updatedAt)}
                                    commentCount={topic.commentCount}
                                />
                            ))}
                        </List>
                    )}
                    <div className="border-t border-border px-4 py-2.5 sm:px-5">
                        <Link href={`/communityTopic/listCommunity/${community.id}`} className="text-sm text-link hover:underline">
                            {t('See all %topics%')}
                        </Link>
                    </div>
                </Panel>
            )}

            {recentEvents !== null && (
                <Panel
                    flush
                    title={t('Recent events')}
                    right={
                        canPostEvent && (
                            <ActionLink href={`/communityEvent/new/${community.id}`} variant="outline" size="sm">
                                <Plus className="size-4" strokeWidth={2.25} aria-hidden />
                                {t('Create an event')}
                            </ActionLink>
                        )
                    }
                >
                    {recentEvents.length === 0 ? (
                        <p className="px-4 py-4 text-sm text-muted-foreground sm:px-5">{t('No events to show.')}</p>
                    ) : (
                        <List>
                            {recentEvents.map((event) => (
                                <EntryRow
                                    key={event.id}
                                    href={`/communityEvent/${event.id}`}
                                    author={event.author}
                                    content={event.name}
                                    date={`${t('Open date')}: ${date.civilDate(event.openDate)}`}
                                    commentCount={event.commentCount}
                                    participantCount={event.participantCount}
                                />
                            ))}
                        </List>
                    )}
                    <div className="border-t border-border px-4 py-2.5 sm:px-5">
                        <Link href={`/communityEvent/listCommunity/${community.id}`} className="text-sm text-link hover:underline">
                            {t('See all events')}
                        </Link>
                    </div>
                </Panel>
            )}

            {members.length > 0 && (
                <Panel title={t('Members')}>
                    <ul className="flex flex-wrap gap-4">
                        {members.map((member) => (
                            <li key={member.id} className="w-16">
                                <Link href={`/member/${member.id}`} className="flex flex-col items-center gap-1">
                                    <Avatar id={member.id} name={member.name} src={member.imageUrl} color={member.avatarColor} size="lg" decorative />
                                    <span className="w-full truncate text-center text-xs">{member.name}</span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </Panel>
            )}
        </>
    );
}
