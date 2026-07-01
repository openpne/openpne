import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Avatar } from '@/components/avatar';
import { useConfirm } from '@/components/confirm-dialog';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { CommunitySummary, EventDetail, EventThread, TopicImage } from '../types';

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

function ImageGrid({ images }: { images: TopicImage[] }) {
    if (images.length === 0) {
        return null;
    }

    return (
        <ul className="mt-2 flex flex-wrap gap-2">
            {images.map((image) => (
                <li key={image.id}>
                    <a href={image.url} target="_blank" rel="noopener noreferrer">
                        <img src={image.thumbnailUrl} alt="" className="size-24 rounded object-cover" />
                    </a>
                </li>
            ))}
        </ul>
    );
}

export default function CommunityEventShow() {
    const t = useT();
    const confirm = useConfirm();
    const { community, event, thread, canComment, canEdit, isParticipant, rosterOpen, isFull, flash } = usePage<ShowProps>().props;

    // Mirror the OpenPNE 3 pager URL: order dropped when default (desc), page dropped when 1.
    const threadLink = (page: number, ascending: boolean) => {
        const params = new URLSearchParams();
        if (ascending) params.set('order', 'asc');
        if (page > 1) params.set('page', String(page));
        const qs = params.toString();
        return `/m/community/event/${event.id}${qs ? `?${qs}` : ''}`;
    };

    // OpenPNE 3 posts RSVP through the comment endpoint: the participate/cancel buttons toggle the
    // roster and save the (required) comment; "comment only" (comment=1) skips the toggle.
    const form = useForm({ body: '', images: [] as File[] });
    const submit = (commentOnly: boolean) => {
        form.transform((data) => (commentOnly ? { ...data, comment: '1' } : data));
        form.post(`/m/community/event/${event.id}/comment`, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => form.reset('body', 'images'),
        });
    };

    const deleteEvent = async () => {
        if (await confirm({ title: t('Delete this event?'), description: event.name, confirmLabel: t('Delete'), danger: true })) {
            router.post(`/m/community/event/${event.id}/delete`);
        }
    };

    const deleteComment = async (commentId: number) => {
        if (await confirm({ title: t('Delete this comment?'), confirmLabel: t('Delete'), danger: true })) {
            router.post(`/m/community/event/comment/${commentId}/delete`, {}, { preserveScroll: true });
        }
    };

    const bodyEmpty = form.data.body.trim() === '';

    return (
        <>
            <Head title={event.name} />
            <main className="mx-auto max-w-2xl space-y-4 px-4 py-8">
                {flash.status && <p role="status">{flash.status}</p>}
                {flash.error && <p role="alert">{flash.error}</p>}

                <p className="text-sm">
                    <Link href={`/m/community/${community.id}/event`} className="text-muted-foreground hover:underline">
                        {community.name} &mdash; {t('Events')}
                    </Link>
                </p>

                <article className="space-y-3">
                    <h1 className="text-2xl font-semibold">{event.name}</h1>
                    <div className="flex items-center gap-2 text-sm text-muted-foreground">
                        <Avatar id={event.author?.id ?? 0} name={event.author?.name ?? ''} src={event.author?.imageUrl ?? null} size="sm" />
                        {event.author ? (
                            <Link href={`/m/member/${event.author.id}`} className="hover:underline">
                                {event.author.name}
                            </Link>
                        ) : (
                            <span>{t('Withdrawn member')}</span>
                        )}
                        <span>&mdash; {new Date(event.createdAt).toLocaleString()}</span>
                    </div>

                    <dl className="grid grid-cols-[max-content_1fr] gap-x-4 gap-y-1 text-sm">
                        <dt className="text-muted-foreground">{t('Open date')}</dt>
                        <dd>
                            {new Date(event.openDate).toLocaleDateString()}
                            {event.openDateComment && <span className="text-muted-foreground"> ({event.openDateComment})</span>}
                        </dd>
                        {event.area && (
                            <>
                                <dt className="text-muted-foreground">{t('Area')}</dt>
                                <dd>{event.area}</dd>
                            </>
                        )}
                        {event.applicationDeadline && (
                            <>
                                <dt className="text-muted-foreground">{t('Application deadline')}</dt>
                                <dd>{new Date(event.applicationDeadline).toLocaleDateString()}</dd>
                            </>
                        )}
                        <dt className="text-muted-foreground">{t('Count of Member')}</dt>
                        <dd>
                            {event.capacity != null ? `${event.participantCount} / ${event.capacity}` : event.participantCount}
                            {' '}
                            <Link href={`/m/community/event/${event.id}/members`} className="hover:underline">
                                {t('See Member List')}
                            </Link>
                        </dd>
                    </dl>

                    <div className="whitespace-pre-wrap break-words">{event.body}</div>
                    <ImageGrid images={event.images} />

                    {canEdit && (
                        <div className="flex gap-4 text-sm">
                            <Link href={`/m/community/event/${event.id}/edit`} className="hover:underline">
                                {t('Edit')}
                            </Link>
                            <button type="button" onClick={deleteEvent} className="text-red-600 hover:underline">
                                {t('Delete')}
                            </button>
                        </div>
                    )}
                </article>

                <section className="space-y-3">
                    <h2 className="text-lg font-semibold">{t(':count comments', { count: thread.total })}</h2>

                    {thread.lastPage > 1 && (
                        <div className="flex items-center justify-between gap-2 text-sm">
                            {thread.hasOlder && thread.olderPage !== null ? (
                                <Link href={threadLink(thread.olderPage, thread.ascending)} preserveScroll className="hover:underline">
                                    {t('Older')}
                                </Link>
                            ) : (
                                <span />
                            )}
                            <Link href={threadLink(1, !thread.ascending)} preserveScroll className="hover:underline">
                                {thread.ascending ? t('View Latest') : t('View Oldest First')}
                            </Link>
                            {thread.hasNewer && thread.newerPage !== null ? (
                                <Link href={threadLink(thread.newerPage, thread.ascending)} preserveScroll className="hover:underline">
                                    {t('Newer')}
                                </Link>
                            ) : (
                                <span />
                            )}
                        </div>
                    )}

                    {thread.comments.length === 0 ? (
                        <p className="text-sm text-muted-foreground">{t('No comments yet.')}</p>
                    ) : (
                        <ul className="space-y-3">
                            {thread.comments.map((comment) => (
                                <li key={comment.id} className="border-t pt-3">
                                    <div className="flex items-baseline gap-2 text-sm text-muted-foreground">
                                        <span className="font-medium">#{comment.number}</span>
                                        {comment.author ? (
                                            <Link href={`/m/member/${comment.author.id}`} className="hover:underline">
                                                {comment.author.name}
                                            </Link>
                                        ) : (
                                            <span>{t('Withdrawn member')}</span>
                                        )}
                                        <span className="ml-auto">{new Date(comment.createdAt).toLocaleString()}</span>
                                        {comment.deletable && (
                                            <button type="button" onClick={() => deleteComment(comment.id)} className="text-red-600 hover:underline">
                                                {t('Delete')}
                                            </button>
                                        )}
                                    </div>
                                    <p className="mt-1 whitespace-pre-wrap break-words">{comment.body}</p>
                                    <ImageGrid images={comment.images} />
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                {canComment && (
                    <form onSubmit={(e) => e.preventDefault()} className="space-y-2">
                        <h2 className="text-lg font-semibold">{t('Post a new event comment')}</h2>
                        <label htmlFor="comment_body">{t('Comment')}</label>
                        <textarea
                            id="comment_body"
                            value={form.data.body}
                            onChange={(e) => form.setData('body', e.target.value)}
                            required
                            rows={5}
                            className="w-full rounded border px-2 py-1"
                        />
                        {form.errors.body && <p role="alert">{form.errors.body}</p>}
                        <div>
                            <label htmlFor="comment_images">{t('Images')}</label>
                            <input
                                id="comment_images"
                                type="file"
                                accept="image/jpeg,image/png,image/gif,image/webp"
                                multiple
                                onChange={(e) => form.setData('images', Array.from(e.target.files ?? []).slice(0, 3))}
                            />
                            {form.errors.images && <p role="alert">{form.errors.images}</p>}
                        </div>

                        {/* RSVP + comment share one form (OpenPNE 3): participate/cancel toggle the roster,
                            comment-only skips it. A comment is required for every submit. */}
                        <div className="flex flex-wrap gap-3">
                            {rosterOpen && isParticipant && (
                                <button
                                    type="button"
                                    onClick={() => submit(false)}
                                    disabled={form.processing || bodyEmpty}
                                    className="min-h-11 rounded-full bg-slate-100 px-5 text-sm font-medium text-slate-700 transition hover:bg-slate-200 disabled:opacity-50 dark:bg-slate-700 dark:text-slate-200 dark:hover:bg-slate-600"
                                >
                                    {t('Cancel to join')}
                                </button>
                            )}
                            {rosterOpen && !isParticipant && !isFull && (
                                <button
                                    type="button"
                                    onClick={() => submit(false)}
                                    disabled={form.processing || bodyEmpty}
                                    className="min-h-11 rounded-full bg-blue-600 px-5 text-sm font-medium text-white transition hover:bg-blue-700 disabled:opacity-50"
                                >
                                    {t('Participate in this event')}
                                </button>
                            )}
                            {rosterOpen && !isParticipant && isFull && <p className="self-center text-sm text-red-600">{t('This event is full.')}</p>}
                            <button
                                type="button"
                                onClick={() => submit(true)}
                                disabled={form.processing || bodyEmpty}
                                className="min-h-11 rounded-full border px-5 text-sm font-medium transition hover:bg-muted/50 disabled:opacity-50"
                            >
                                {t('Add a comment only')}
                            </button>
                        </div>
                    </form>
                )}
            </main>
        </>
    );
}
