import { Head, Link, router, usePage } from '@inertiajs/react';
import { Avatar } from '@/components/avatar';
import { Card, CardBody } from '@/components/card';
import { CommunityImage } from '@/components/community-image';
import { useConfirm } from '@/components/confirm-dialog';
import { formatDateOnly } from '@/lib/date';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { CommunityDetail, CommunityMemberRow, CommunityRoleSlug, EventSummary, TopicSummary } from './types';

interface ShowProps extends PageProps {
    community: CommunityDetail;
    viewerRole: CommunityRoleSlug | null;
    isPending: boolean;
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
    const confirm = useConfirm();
    const {
        community, viewerRole, canManage, isPending, canJoin, canLeave, members,
        recentTopics, canPostTopic, recentEvents, canPostEvent, flash,
    } = usePage<ShowProps>().props;

    const join = () => router.post(`/m/community/${community.id}/join`);
    const leave = async () => {
        if (await confirm({ title: t('Leave this %community%?'), confirmLabel: t('Leave'), danger: true })) {
            router.post(`/m/community/${community.id}/quit`);
        }
    };

    return (
        <>
            <Head title={community.name} />
            <main className="mx-auto max-w-2xl space-y-4 px-4 py-8">
                {flash.status && <p role="status">{flash.status}</p>}
                {flash.error && <p role="alert">{flash.error}</p>}

                <div className="flex items-start gap-4">
                    <CommunityImage id={community.id} name={community.name} src={community.imageUrl} className="size-20" textClassName="text-2xl" />
                    <div className="min-w-0 flex-1">
                        <h1 className="text-2xl font-semibold">{community.name}</h1>
                        {community.category && <p className="text-sm text-muted-foreground">{community.category.name}</p>}
                        <Link href={`/m/community/${community.id}/members`} className="text-sm hover:underline">
                            {t(':count members', { count: community.memberCount })}
                        </Link>
                    </div>
                </div>

                {isPending && (
                    <Card>
                        <CardBody>{t('Your join request is awaiting approval.')}</CardBody>
                    </Card>
                )}

                {(canJoin || canLeave) && (
                    <div className="flex gap-3">
                        {canJoin && (
                            <button
                                type="button"
                                onClick={join}
                                className="min-h-11 rounded-full bg-blue-600 px-5 text-sm font-medium text-white transition hover:bg-blue-700"
                            >
                                {community.registerPolicy === 'approval' ? t('Request to join') : t('Join')}
                            </button>
                        )}
                        {canLeave && (
                            <button
                                type="button"
                                onClick={leave}
                                className="min-h-11 rounded-full bg-slate-100 px-5 text-sm font-medium text-slate-700 transition hover:bg-slate-200 dark:bg-slate-700 dark:text-slate-200 dark:hover:bg-slate-600"
                            >
                                {t('Leave')}
                            </button>
                        )}
                    </div>
                )}

                {canManage && (
                    <div className="flex gap-4 text-sm">
                        <Link href={`/m/community/edit?id=${community.id}`} className="hover:underline">
                            {t('Edit %community%')}
                        </Link>
                        {viewerRole === 'admin' && (
                            <Link href={`/m/community/${community.id}/pending`} className="hover:underline">
                                {t('Pending members')}
                            </Link>
                        )}
                    </div>
                )}

                {community.description && <div className="whitespace-pre-wrap">{community.description}</div>}

                {recentTopics !== null && (
                    <section className="space-y-2">
                        <div className="flex items-center justify-between gap-3">
                            <h2 className="text-lg font-semibold">{t('Recent %topics%')}</h2>
                            {canPostTopic && (
                                <Link href={`/m/community/${community.id}/topic/new`} className="shrink-0 text-sm hover:underline">
                                    {t('Post a new %topic%')}
                                </Link>
                            )}
                        </div>
                        {recentTopics.length === 0 ? (
                            <p className="text-sm text-muted-foreground">{t('No %topics% to show.')}</p>
                        ) : (
                            <ul className="divide-y">
                                {recentTopics.map((topic) => (
                                    <li key={topic.id}>
                                        <Link
                                            href={`/m/community/topic/${topic.id}`}
                                            className="block truncate py-2 hover:bg-muted/40"
                                        >
                                            <span className="font-medium">{topic.name}</span>{' '}
                                            <span className="text-sm text-muted-foreground">({topic.commentCount})</span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                        <Link href={`/m/community/${community.id}/topic`} className="text-sm hover:underline">
                            {t('See all %topics%')}
                        </Link>
                    </section>
                )}

                {recentEvents !== null && (
                    <section className="space-y-2">
                        <div className="flex items-center justify-between gap-3">
                            <h2 className="text-lg font-semibold">{t('Recent events')}</h2>
                            {canPostEvent && (
                                <Link href={`/m/community/${community.id}/event/new`} className="shrink-0 text-sm hover:underline">
                                    {t('Post a new event')}
                                </Link>
                            )}
                        </div>
                        {recentEvents.length === 0 ? (
                            <p className="text-sm text-muted-foreground">{t('No events to show.')}</p>
                        ) : (
                            <ul className="divide-y">
                                {recentEvents.map((event) => (
                                    <li key={event.id}>
                                        <Link href={`/m/community/event/${event.id}`} className="block truncate py-2 hover:bg-muted/40">
                                            <span className="font-medium">{event.name}</span>{' '}
                                            <span className="text-sm text-muted-foreground">
                                                ({event.commentCount}) &middot; {formatDateOnly(event.openDate)}
                                            </span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                        <Link href={`/m/community/${community.id}/event`} className="text-sm hover:underline">
                            {t('See all events')}
                        </Link>
                    </section>
                )}

                {members.length > 0 && (
                    <section className="space-y-2">
                        <h2 className="text-lg font-semibold">{t('Members')}</h2>
                        <ul className="flex flex-wrap gap-4">
                            {members.map((member) => (
                                <li key={member.id} className="w-16">
                                    <Link href={`/m/member/${member.id}`} className="flex flex-col items-center gap-1">
                                        <Avatar id={member.id} name={member.name} src={member.imageUrl} size="lg" />
                                        <span className="w-full truncate text-center text-xs">{member.name}</span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </main>
        </>
    );
}
