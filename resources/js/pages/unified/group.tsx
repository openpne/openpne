import { Head, Link, router, usePage } from '@inertiajs/react';
import { Activity, CalendarDays, MessageSquareText, MessagesSquare, Plus, UserCircle2, Users } from 'lucide-react';
import { useConfirm } from '@/components/confirm-dialog';
import { CountPill } from '@/components/count-pill';
import { CivilDate, Timestamp } from '@/components/timestamp';
import { ActionLink } from '@/components/ui/action-link';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import type { NineTableItem, PageProps } from '@/types';
import type { CommunityRoleSlug, EventSummary, TopicSummary } from '../community/types';
import { BoardCards } from './board-cards';
import { GroupGrid, type HomeGroup } from './group-grid';
import { HOME_CARD, HomeSection, SubSection } from './home-section';
import { PeopleGrid } from './people-grid';
import { type HomePhoto, PhotoGrid } from './photo-grid';
import { ProfileHeader, type UnifiedProfile } from './profile-header';

/** The hero's identity block, plus the facts about the group that belong beside its name. */
interface UnifiedGroupIdentity extends UnifiedProfile {
    memberCount: number;
    categoryName: string | null;
    registerPolicy: 'open' | 'approval'; // drives the join-button label
}

interface UnifiedGroupProps extends PageProps {
    group: UnifiedGroupIdentity;
    viewerRole: CommunityRoleSlug | null;
    isPending: boolean;
    isTransferNominee: boolean; // the viewer is the pending admin-transfer nominee → the accept/reject card
    canManage: boolean;
    canJoin: boolean;
    canLeave: boolean;
    members: NineTableItem[];
    categoryGroups: (HomeGroup & { memberCount: number })[];
    recentTopics: TopicSummary[] | null; // null → the viewer may not read the boards
    canPostTopic: boolean;
    recentEvents: EventSummary[] | null;
    canPostEvent: boolean;
    canViewTalk: boolean; // the talk unit is on AND the viewer may read this group's talk
    talkPreview: { body: string; authorName: string | null; createdAt: string } | null;
    talkUnread: number; // the viewer's own unread here; 0 for a non-member, who holds no cursor
    recentPhotos: HomePhoto[];
}

/**
 * The unified group page (SnsSettingKey::ModernUnifiedHome): the unified layout's grammar turned on a
 * group — what it is, how to be in it, what is being said in it, and who else is around. Read
 * vertically, like the home and the member page, so the three read as one surface.
 *
 * Every entrance the shipped group page offers is here with the same condition and the same
 * destination; what the layout adds is a way sideways (the groups filed beside this one) and a look
 * at the group rather than a list of it (the pictures its content was posted with).
 */
export default function UnifiedGroup() {
    const t = useT();
    const confirm = useConfirm();
    const {
        group, viewerRole, isPending, isTransferNominee, canManage, canJoin, canLeave, members,
        categoryGroups, recentTopics, canPostTopic, recentEvents, canPostEvent, canViewTalk,
        talkPreview, talkUnread, recentPhotos,
    } = usePage<UnifiedGroupProps>().props;

    const join = () => router.post(`/groups/${group.id}/join`);
    const leave = async () => {
        if (await confirm({ title: t('Leave this %community%?'), confirmLabel: t('Leave'), danger: true })) {
            router.post(`/groups/${group.id}/quit`);
        }
    };

    // The roster the member count has always linked to — an OpenPNE 3 URL, kept.
    const memberListHref = `/community/member/list?id=${group.id}`;
    const hasActions = isPending || canJoin || canLeave || canManage;
    const hasMoments = recentPhotos.length > 0 || recentTopics !== null || recentEvents !== null;

    return (
        <>
            <Head title={group.name} />

            {/* Above the hero, not inside it: this is an errand addressed to one member, and it has to
                be answered before anything the page says about the group matters. */}
            {isTransferNominee && (
                <section className={`${HOME_CARD} space-y-3 px-4 py-4 sm:px-5`}>
                    <p className="text-sm">{t('The administrator of this %community% asks you to take over the administration.')}</p>
                    <div className="flex gap-3">
                        <Button type="button" onClick={() => router.post(`/groups/${group.id}/members/transfer/accept`)}>
                            {t('Accept')}
                        </Button>
                        <Button type="button" variant="secondary" onClick={() => router.post(`/groups/${group.id}/members/transfer/reject`)}>
                            {t('Decline')}
                        </Button>
                    </div>
                </section>
            )}

            <ProfileHeader
                profile={group}
                as="h1"
                // The description is what a group is for, and nothing below the hero repeats it — so it
                // is read here in full rather than clamped to a lead-in.
                clampBio={false}
                meta={
                    <div className="mt-0.5 space-y-0.5 text-sm text-muted-foreground">
                        {group.categoryName && <p>{group.categoryName}</p>}
                        <p>
                            <Link href={memberListHref} className="text-link hover:underline">
                                {t(':count members', { count: group.memberCount })}
                            </Link>
                        </p>
                    </div>
                }
                actions={
                    hasActions ? (
                        <div className="mt-3 space-y-3">
                            {isPending && <p className="text-sm text-muted-foreground">{t('Your join request is awaiting approval.')}</p>}

                            {(canJoin || canLeave) && (
                                <div className="flex flex-wrap justify-center gap-3">
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
                                <div className="flex flex-wrap justify-center gap-4 text-sm">
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
                        </div>
                    ) : undefined
                }
            />

            {/* Directly under the hero, as on the shipped page: the conversation is the thing a member
                comes back for, and the row is its entrance — the empty state carries it too, since a
                group whose talk has not started is exactly the one that needs a way in. */}
            {canViewTalk && (
                <HomeSection
                    title={t('Talk')}
                    icon={MessagesSquare}
                    right={<CountPill count={talkUnread} label={t(':count unread messages', { count: talkUnread })} />}
                >
                    <Link
                        href={`/groups/${group.id}/talk`}
                        className="flex items-center gap-2 rounded-xl border border-border px-3.5 py-3 transition-colors hover:bg-muted/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        <span className="min-w-0 flex-1 truncate text-sm">
                            {talkPreview === null ? (
                                <span className="text-muted-foreground">{t('No messages yet.')}</span>
                            ) : (
                                <>
                                    <span className="text-muted-foreground">{talkPreview.authorName ?? t('Withdrawn member')}</span>
                                    {/* Same separator as the room list: one preview idiom. */}
                                    {': '}
                                    {talkPreview.body}
                                </>
                            )}
                        </span>
                        {talkPreview !== null && (
                            <Timestamp at={talkPreview.createdAt} preset="relative" className="shrink-0 text-xs text-muted-foreground" />
                        )}
                    </Link>
                </HomeSection>
            )}

            {members.length > 0 && (
                <HomeSection title={t('Members')} icon={UserCircle2} viewAll={{ label: t('View all'), href: memberListHref }}>
                    <PeopleGrid people={members} />
                </HomeSection>
            )}

            {/* Sideways rather than deeper: a group's category is public to anyone who may open this
                page, so the groups filed under it are a way on from here. */}
            {categoryGroups.length > 0 && (
                <HomeSection title={t('%Communities% in the same category')} icon={Users}>
                    <GroupGrid
                        groups={categoryGroups.map((related) => ({
                            ...related,
                            caption: t(':count members', { count: related.memberCount }),
                        }))}
                    />
                </HomeSection>
            )}

            {hasMoments && (
                <HomeSection title={t('Recent moments')} icon={Activity} bodyClassName="space-y-5">
                    {recentPhotos.length > 0 && (
                        <SubSection title={t('Recent photos')}>
                            <PhotoGrid photos={recentPhotos} />
                        </SubSection>
                    )}

                    {recentTopics !== null && (
                        <SubSection
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
                                <p className="text-sm text-muted-foreground">{t('No %topics% to show.')}</p>
                            ) : (
                                <BoardCards
                                    icon={MessageSquareText}
                                    rows={recentTopics.map((topic) => ({
                                        id: topic.id,
                                        href: `/topics/${topic.id}`,
                                        name: topic.name,
                                        date: <Timestamp at={topic.updatedAt} preset="listStamp" />,
                                        commentCount: topic.commentCount,
                                    }))}
                                />
                            )}
                            <SeeAll href={`/groups/${group.id}/topics`} label={t('See all %topics%')} />
                        </SubSection>
                    )}

                    {recentEvents !== null && (
                        <SubSection
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
                                <p className="text-sm text-muted-foreground">{t('No events to show.')}</p>
                            ) : (
                                <BoardCards
                                    icon={CalendarDays}
                                    rows={recentEvents.map((event) => ({
                                        id: event.id,
                                        href: `/events/${event.id}`,
                                        name: event.name,
                                        date: (
                                            <>
                                                {t('Open date')}: <CivilDate value={event.openDate} weekday />
                                            </>
                                        ),
                                        commentCount: event.commentCount,
                                        participantCount: event.participantCount,
                                    }))}
                                />
                            )}
                            <SeeAll href={`/groups/${group.id}/events`} label={t('See all events')} />
                        </SubSection>
                    )}
                </HomeSection>
            )}
        </>
    );
}

/** The way from a board's latest few to the board itself, under the rows where it is read last. */
function SeeAll({ href, label }: { href: string; label: string }) {
    return (
        <Link href={href} className="mt-2 inline-block text-sm text-link hover:underline">
            {label}
        </Link>
    );
}
