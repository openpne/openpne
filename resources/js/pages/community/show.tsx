import { Head, Link, router, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { Avatar } from '@/components/avatar';
import { CommunityImage } from '@/components/community-image';
import { CountPill } from '@/components/count-pill';
import { EntryRow } from '@/components/entry-row';
import { useConfirm } from '@/components/confirm-dialog';
import { CivilDate, Timestamp } from '@/components/timestamp';
import { Heading } from '@/components/ui/heading';
import { UserText } from '@/components/user-text';
import { ActionLink } from '@/components/ui/action-link';
import { Button } from '@/components/ui/button';
import { List, Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { CommunityDetail, CommunityMemberRow, CommunityRoleSlug, EventSummary, TopicSummary } from './types';

interface ShowProps extends PageProps {
    group: CommunityDetail;
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
    canViewTalk: boolean; // the talk unit is on AND the viewer may read this group's talk
    talkPreview: { body: string; authorName: string | null; createdAt: string } | null;
    talkUnread: number; // the viewer's own unread here; 0 for a non-member, who holds no cursor
}

export default function CommunityShow() {
    const t = useT();
    const confirm = useConfirm();
    const {
        group, viewerRole, canManage, isPending, isTransferNominee, canJoin, canLeave, members,
        recentTopics, canPostTopic, recentEvents, canPostEvent, canViewTalk, talkPreview, talkUnread,
    } = usePage<ShowProps>().props;

    const join = () => router.post(`/groups/${group.id}/join`);
    const leave = async () => {
        if (await confirm({ title: t('Leave this %community%?'), confirmLabel: t('Leave'), danger: true })) {
            router.post(`/groups/${group.id}/quit`);
        }
    };

    return (
        <>
            <Head title={group.name} />

            {isTransferNominee && (
                <Panel bodyClassName="space-y-3">
                    <p className="text-sm">{t('The administrator of this %community% asks you to take over the administration.')}</p>
                    <div className="flex gap-3">
                        <Button type="button" onClick={() => router.post(`/groups/${group.id}/members/transfer/accept`)}>
                            {t('Accept')}
                        </Button>
                        <Button type="button" variant="secondary" onClick={() => router.post(`/groups/${group.id}/members/transfer/reject`)}>
                            {t('Decline')}
                        </Button>
                    </div>
                </Panel>
            )}

            <Panel bodyClassName="space-y-4">
                <div className="flex items-start gap-4">
                    <CommunityImage name={group.name} src={group.imageUrl} className="size-20" textClassName="text-2xl" />
                    <div className="min-w-0 flex-1">
                        <Heading variant="page">{group.name}</Heading>
                        {group.category && <p className="text-sm text-muted-foreground">{group.category.name}</p>}
                        <Link href={`/community/member/list?id=${group.id}`} className="text-sm text-link hover:underline">
                            {t(':count members', { count: group.memberCount })}
                        </Link>
                    </div>
                </div>

                {isPending && <p className="text-sm text-muted-foreground">{t('Your join request is awaiting approval.')}</p>}

                {(canJoin || canLeave) && (
                    <div className="flex gap-3">
                        {canJoin && (
                            <Button type="button" onClick={join}>
                                {group.registerPolicy === 'approval' ? t('Request to join') : t('Join')}
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
                        <Link href={`/groups/edit?id=${group.id}`} className="text-link hover:underline">
                            {t('Edit %community%')}
                        </Link>
                        <Link href={`/community/member/manage/${group.id}`} className="text-link hover:underline">
                            {t('Management member')}
                        </Link>
                        {viewerRole === 'admin' && (
                            <Link href={`/groups/${group.id}/members/pending`} className="text-link hover:underline">
                                {t('Pending members')}
                            </Link>
                        )}
                    </div>
                )}

                {group.description && (
                    <div className="whitespace-pre-wrap break-words">
                        <UserText text={group.description} />
                    </div>
                )}
            </Panel>

            {/* Where the community timeline's box used to sit — the plugin injected it before the
                group's own details, and talk inherits the slot. The server has already answered the
                read gate; a non-member of an Everyone group sees the preview with no unread of their
                own, since only a membership carries a cursor. */}
            {canViewTalk && (
                <Panel
                    flush
                    title={t('Talk')}
                    right={<CountPill count={talkUnread} label={t(':count unread messages')} />}
                >
                    {talkPreview === null ? (
                        <p className="px-4 py-4 text-sm text-muted-foreground sm:px-5">{t('No messages yet.')}</p>
                    ) : (
                        <div className="px-4 py-4 sm:px-5">
                            <p className="truncate text-sm">
                                <span className="text-muted-foreground">
                                    {talkPreview.authorName ?? t('Withdrawn member')}
                                </span>
                                {' — '}
                                {talkPreview.body}
                            </p>
                            <Timestamp at={talkPreview.createdAt} preset="relative" className="text-xs text-muted-foreground" />
                        </div>
                    )}
                    <div className="border-t border-border px-4 py-2.5 sm:px-5">
                        <Link href={`/groups/${group.id}/talk`} className="text-sm text-link hover:underline">
                            {t('Go to talk')}
                        </Link>
                    </div>
                </Panel>
            )}

            {recentTopics !== null && (
                <Panel
                    flush
                    title={t('Recent %topics%')}
                    right={
                        canPostTopic && (
                            <ActionLink href={`/groups/${group.id}/topics/new`} variant="outline" size="sm">
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
                                    href={`/topics/${topic.id}`}
                                    author={topic.author}
                                    content={topic.name}
                                    date={<Timestamp at={topic.updatedAt} preset="listStamp" />}
                                    commentCount={topic.commentCount}
                                />
                            ))}
                        </List>
                    )}
                    <div className="border-t border-border px-4 py-2.5 sm:px-5">
                        <Link href={`/groups/${group.id}/topics`} className="text-sm text-link hover:underline">
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
                            <ActionLink href={`/groups/${group.id}/events/new`} variant="outline" size="sm">
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
                                    href={`/events/${event.id}`}
                                    author={event.author}
                                    content={event.name}
                                    date={<>{t('Open date')}: <CivilDate value={event.openDate} weekday /></>}
                                    commentCount={event.commentCount}
                                    participantCount={event.participantCount}
                                />
                            ))}
                        </List>
                    )}
                    <div className="border-t border-border px-4 py-2.5 sm:px-5">
                        <Link href={`/groups/${group.id}/events`} className="text-sm text-link hover:underline">
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
