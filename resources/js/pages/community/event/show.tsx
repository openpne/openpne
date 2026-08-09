import { LinkCard } from '@/components/link-card';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ImageGrid } from '@/components/image-grid';
import { ImagesField } from '@/components/images-field';
import { Avatar } from '@/components/avatar';
import { useConfirm } from '@/components/confirm-dialog';
import { RichBody } from '@/components/rich-body';
import { Heading } from '@/components/ui/heading';
import { UserText } from '@/components/user-text';
import { Button } from '@/components/ui/button';
import { dangerActionClass } from '@/components/ui/danger-link';
import { Field } from '@/components/ui/field';
import { List, Panel } from '@/components/ui/surface';
import { Textarea } from '@/components/ui/textarea';
import { formatDate, formatDateTime } from '@/lib/date';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { CommunitySummary, EventDetail, EventThread } from '../types';

interface ShowProps extends PageProps {
    community: CommunitySummary;
    event: EventDetail;
    thread: EventThread;
    canComment: boolean;
    canEdit: boolean;
    isParticipant: boolean;
    rosterOpen: boolean; // not closed and not past the deadline
    isFull: boolean;
}

export default function CommunityEventShow() {
    const t = useT();
    const confirm = useConfirm();
    const { event, thread, canComment, canEdit, isParticipant, rosterOpen, isFull } = usePage<ShowProps>().props;

    // Mirror the OpenPNE 3 pager URL: order dropped when default (desc), page dropped when 1.
    const threadLink = (page: number, ascending: boolean) => {
        const params = new URLSearchParams();
        if (ascending) params.set('order', 'asc');
        if (page > 1) params.set('page', String(page));
        const qs = params.toString();
        return `/communityEvent/${event.id}${qs ? `?${qs}` : ''}`;
    };

    // OpenPNE 3 posts RSVP through the comment endpoint: the participate/cancel buttons toggle the
    // roster and save the (required) comment; "comment only" (comment=1) skips the toggle.
    const form = useForm({ body: '', images: [] as File[] });
    const submit = (commentOnly: boolean) => {
        form.transform((data) => (commentOnly ? { ...data, comment: '1' } : data));
        form.post(`/communityEvent/${event.id}/comment/create`, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => form.reset('body', 'images'),
        });
    };

    const deleteEvent = async () => {
        if (await confirm({ title: t('Delete this event?'), description: event.name, confirmLabel: t('Delete'), danger: true })) {
            router.post(`/communityEvent/delete/${event.id}`);
        }
    };

    const deleteComment = async (commentId: number) => {
        if (await confirm({ title: t('Delete this comment?'), confirmLabel: t('Delete'), danger: true })) {
            router.post(`/communityEvent/comment/delete/${commentId}`, {}, { preserveScroll: true });
        }
    };

    const bodyEmpty = form.data.body.trim() === '';

    return (
        <>
            <Head title={event.name} />

            <Heading variant="page">{event.name}</Heading>

            <Panel bodyClassName="space-y-3">
                <div className="flex items-center gap-2 text-sm text-muted-foreground">
                    <Avatar id={event.author?.id ?? 0} name={event.author?.name ?? ''} src={event.author?.imageUrl ?? null} color={event.author?.avatarColor ?? null} size="sm" decorative />
                    {event.author ? (
                        <Link href={`/member/${event.author.id}`} className="text-link hover:underline">
                            {event.author.name}
                        </Link>
                    ) : (
                        <span>{t('Withdrawn member')}</span>
                    )}
                    <span>&mdash; {formatDateTime(event.createdAt)}</span>
                </div>

                <dl className="grid grid-cols-[max-content_1fr] gap-x-4 gap-y-1 text-sm">
                    <dt className="text-muted-foreground">{t('Open date')}</dt>
                    <dd>
                        {formatDate(event.openDate)}
                        {event.openDateComment && <span className="text-muted-foreground"> ({event.openDateComment})</span>}
                    </dd>
                    {event.area && (
                        <>
                            <dt className="text-muted-foreground">{t('Area')}</dt>
                            <dd className="whitespace-pre-wrap break-words">
                                <UserText text={event.area} />
                            </dd>
                        </>
                    )}
                    {event.applicationDeadline && (
                        <>
                            <dt className="text-muted-foreground">{t('Application deadline')}</dt>
                            <dd>{formatDate(event.applicationDeadline)}</dd>
                        </>
                    )}
                    <dt className="text-muted-foreground">{t('Count of Member')}</dt>
                    <dd>
                        {event.capacity != null ? `${event.participantCount} / ${event.capacity}` : event.participantCount}
                        {' '}
                        <Link href={`/communityEvent/${event.id}/memberList`} className="text-link hover:underline">
                            {t('See Member List')}
                        </Link>
                    </dd>
                </dl>

                <RichBody body={event.body} bodyHtml={event.bodyHtml} />
                <LinkCard card={event.linkCard} />
                    <ImageGrid images={event.images} size="size-24" className="mt-2" />

                {canEdit && (
                    <div className="flex gap-4 text-sm">
                        <Link href={`/communityEvent/edit/${event.id}`} className="text-link hover:underline">
                            {t('Edit')}
                        </Link>
                        <button type="button" onClick={deleteEvent} className={dangerActionClass}>
                            {t('Delete')}
                        </button>
                    </div>
                )}
            </Panel>

            <Panel title={t(':count comments', { count: thread.total })} flush>
                {thread.lastPage > 1 && (
                    <div className="flex items-center justify-between gap-2 border-b border-border px-4 py-2.5 text-sm sm:px-5">
                        {thread.hasOlder && thread.olderPage !== null ? (
                            <Link href={threadLink(thread.olderPage, thread.ascending)} preserveScroll className="text-link hover:underline">
                                {t('Older')}
                            </Link>
                        ) : (
                            <span />
                        )}
                        <Link href={threadLink(1, !thread.ascending)} preserveScroll className="text-link hover:underline">
                            {thread.ascending ? t('View Latest') : t('View Oldest First')}
                        </Link>
                        {thread.hasNewer && thread.newerPage !== null ? (
                            <Link href={threadLink(thread.newerPage, thread.ascending)} preserveScroll className="text-link hover:underline">
                                {t('Newer')}
                            </Link>
                        ) : (
                            <span />
                        )}
                    </div>
                )}

                {thread.comments.length === 0 ? (
                    <p className="px-4 py-4 text-sm text-muted-foreground sm:px-5">{t('No comments yet.')}</p>
                ) : (
                    <List>
                        {thread.comments.map((comment) => (
                            <li key={comment.id} className="px-4 py-4 sm:px-5">
                                <div className="flex items-center gap-2 text-sm text-muted-foreground">
                                    <Avatar id={comment.author?.id ?? 0} name={comment.author?.name ?? ''} src={comment.author?.imageUrl ?? null} color={comment.author?.avatarColor ?? null} size="sm" decorative />
                                    {comment.author ? (
                                        <Link href={`/member/${comment.author.id}`} className="truncate text-link hover:underline">
                                            {comment.author.name}
                                        </Link>
                                    ) : (
                                        <span className="truncate">{t('Withdrawn member')}</span>
                                    )}
                                    <span className="ml-auto shrink-0 font-medium">#{comment.number}</span>
                                    <span className="shrink-0">{formatDateTime(comment.createdAt)}</span>
                                    {comment.deletable && (
                                        <button type="button" onClick={() => deleteComment(comment.id)} className={`${dangerActionClass} shrink-0`}>
                                            {t('Delete')}
                                        </button>
                                    )}
                                </div>
                                <p className="mt-1 whitespace-pre-wrap break-words">
                                    <UserText text={comment.body} />
                                </p>
                                <ImageGrid images={comment.images} size="size-24" className="mt-2" />
                            </li>
                        ))}
                    </List>
                )}
            </Panel>

            {canComment && (
                <Panel title={t('Post a new event comment')}>
                    <form onSubmit={(e) => e.preventDefault()} className="space-y-3">
                        <Field label={t('Comment')} htmlFor="comment_body" error={form.errors.body}>
                            <Textarea id="comment_body" required rows={5} value={form.data.body} onChange={(e) => form.setData('body', e.target.value)} />
                        </Field>
                        <ImagesField id="comment_images" label={t('Images')} files={form.data.images} onChange={(files) => form.setData('images', files)} errors={form.errors} />

                        {/* RSVP + comment share one form: participate/cancel toggle the roster,
                            comment-only skips it. A comment is required for every submit. */}
                        <div className="flex flex-wrap items-center gap-3">
                            {rosterOpen && isParticipant && (
                                <Button type="button" variant="secondary" onClick={() => submit(false)} disabled={form.processing || bodyEmpty}>
                                    {t('Cancel to join')}
                                </Button>
                            )}
                            {rosterOpen && !isParticipant && !isFull && (
                                <Button type="button" onClick={() => submit(false)} disabled={form.processing || bodyEmpty}>
                                    {t('Participate in this event')}
                                </Button>
                            )}
                            {rosterOpen && !isParticipant && isFull && <p className="self-center text-sm text-destructive">{t('This event is full.')}</p>}
                            <Button type="button" variant="outline" onClick={() => submit(true)} disabled={form.processing || bodyEmpty}>
                                {t('Add a comment only')}
                            </Button>
                        </div>
                    </form>
                </Panel>
            )}
        </>
    );
}
