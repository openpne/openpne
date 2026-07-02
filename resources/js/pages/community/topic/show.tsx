import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { type FormEvent } from 'react';
import { Avatar } from '@/components/avatar';
import { useConfirm } from '@/components/confirm-dialog';
import { FlashMessage } from '@/components/flash-message';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Textarea } from '@/components/ui/textarea';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { CommunitySummary, TopicDetail, TopicImage, TopicThread } from '../types';

interface ShowProps extends PageProps {
    community: CommunitySummary;
    topic: TopicDetail;
    thread: TopicThread;
    canComment: boolean;
    canEdit: boolean;
}

function ImageGrid({ images }: { images: TopicImage[] }) {
    const t = useT();
    if (images.length === 0) {
        return null;
    }

    return (
        <ul className="mt-2 flex flex-wrap gap-2">
            {images.map((image) => (
                <li key={image.id}>
                    <a href={image.url} target="_blank" rel="noopener noreferrer" aria-label={t('Image')}>
                        <img src={image.thumbnailUrl} alt="" className="size-24 rounded-md object-cover" />
                    </a>
                </li>
            ))}
        </ul>
    );
}

const deleteAction = 'rounded-md text-destructive outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring';

export default function CommunityTopicShow() {
    const t = useT();
    const confirm = useConfirm();
    const { community, topic, thread, canComment, canEdit, flash } = usePage<ShowProps>().props;

    // Mirror the OpenPNE 3 pager URL: order dropped when default (desc), page dropped when 1.
    const threadLink = (page: number, ascending: boolean) => {
        const params = new URLSearchParams();
        if (ascending) params.set('order', 'asc');
        if (page > 1) params.set('page', String(page));
        const qs = params.toString();
        return `/m/community/topic/${topic.id}${qs ? `?${qs}` : ''}`;
    };

    const form = useForm({ body: '', images: [] as File[] });
    const submitComment = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/m/community/topic/${topic.id}/comment`, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => form.reset('body', 'images'),
        });
    };

    const deleteTopic = async () => {
        if (await confirm({ title: t('Delete this %topic%?'), description: topic.name, confirmLabel: t('Delete'), danger: true })) {
            router.post(`/m/community/topic/${topic.id}/delete`);
        }
    };

    const deleteComment = async (commentId: number) => {
        if (await confirm({ title: t('Delete this comment?'), confirmLabel: t('Delete'), danger: true })) {
            router.post(`/m/community/topic/comment/${commentId}/delete`, {}, { preserveScroll: true });
        }
    };

    return (
        <>
            <Head title={topic.name} />
            <main className="mx-auto max-w-2xl space-y-4 px-4 py-8 text-foreground">
                {flash.status && <FlashMessage>{flash.status}</FlashMessage>}
                {flash.error && <FlashMessage variant="error">{flash.error}</FlashMessage>}

                <p className="text-sm">
                    <Link href={`/m/community/${community.id}/topic`} className="text-muted-foreground hover:text-foreground hover:underline">
                        {community.name} &mdash; {t('%Topics%')}
                    </Link>
                </p>

                <article className="space-y-3">
                    <h1 className="text-xl font-semibold">{topic.name}</h1>
                    <div className="flex items-center gap-2 text-sm text-muted-foreground">
                        <Avatar id={topic.author?.id ?? 0} name={topic.author?.name ?? ''} src={topic.author?.imageUrl ?? null} size="sm" />
                        {topic.author ? (
                            <Link href={`/m/member/${topic.author.id}`} className="text-link hover:underline">
                                {topic.author.name}
                            </Link>
                        ) : (
                            <span>{t('Withdrawn member')}</span>
                        )}
                        <span>&mdash; {new Date(topic.createdAt).toLocaleString()}</span>
                    </div>

                    <div className="whitespace-pre-wrap break-words">{topic.body}</div>
                    <ImageGrid images={topic.images} />

                    {canEdit && (
                        <div className="flex gap-4 text-sm">
                            <Link href={`/m/community/topic/${topic.id}/edit`} className="text-link hover:underline">
                                {t('Edit')}
                            </Link>
                            <button type="button" onClick={deleteTopic} className={deleteAction}>
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
                        <p className="text-sm text-muted-foreground">{t('No comments yet.')}</p>
                    ) : (
                        <ul className="space-y-3">
                            {thread.comments.map((comment) => (
                                <li key={comment.id} className="border-t border-border pt-3">
                                    <div className="flex items-baseline gap-2 text-sm text-muted-foreground">
                                        <span className="font-medium">#{comment.number}</span>
                                        {comment.author ? (
                                            <Link href={`/m/member/${comment.author.id}`} className="text-link hover:underline">
                                                {comment.author.name}
                                            </Link>
                                        ) : (
                                            <span>{t('Withdrawn member')}</span>
                                        )}
                                        <span className="ml-auto">{new Date(comment.createdAt).toLocaleString()}</span>
                                        {comment.deletable && (
                                            <button type="button" onClick={() => deleteComment(comment.id)} className={deleteAction}>
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
                    <form onSubmit={submitComment} className="space-y-3">
                        <h2 className="text-lg font-semibold">{t('Post a comment')}</h2>
                        <Field label={t('Comment')} htmlFor="comment_body" error={form.errors.body}>
                            <Textarea id="comment_body" required rows={5} value={form.data.body} onChange={(e) => form.setData('body', e.target.value)} />
                        </Field>
                        <Field label={t('Images')} htmlFor="comment_images" error={form.errors.images}>
                            <input
                                id="comment_images"
                                type="file"
                                accept="image/jpeg,image/png,image/gif,image/webp"
                                multiple
                                onChange={(e) => form.setData('images', Array.from(e.target.files ?? []).slice(0, 3))}
                                className="block w-full text-sm text-muted-foreground file:mr-3 file:rounded-md file:border-0 file:bg-secondary file:px-3 file:py-2 file:text-sm file:font-medium file:text-secondary-foreground hover:file:bg-secondary/80"
                            />
                        </Field>
                        <Button type="submit" loading={form.processing} disabled={form.data.body.trim() === ''}>
                            {t('Post comment')}
                        </Button>
                    </form>
                )}
            </main>
        </>
    );
}
