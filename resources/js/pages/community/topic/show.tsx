import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Avatar } from '@/components/avatar';
import { useConfirm } from '@/components/confirm-dialog';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { CommunitySummary, TopicComment, TopicDetail, TopicImage } from '../types';

interface ShowProps extends PageProps {
    community: CommunitySummary;
    topic: TopicDetail;
    comments: TopicComment[];
    canComment: boolean;
    canEdit: boolean;
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

export default function CommunityTopicShow() {
    const t = useT();
    const confirm = useConfirm();
    const { community, topic, comments, canComment, canEdit, flash } = usePage<ShowProps>().props;

    const form = useForm({ body: '', images: [] as File[] });
    const submitComment = (e: React.FormEvent) => {
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
            <main className="mx-auto max-w-2xl space-y-4 px-4 py-8">
                {flash.status && <p role="status">{flash.status}</p>}
                {flash.error && <p role="alert">{flash.error}</p>}

                <p className="text-sm">
                    <Link href={`/m/community/${community.id}/topic`} className="text-muted-foreground hover:underline">
                        {community.name} &mdash; {t('%Topics%')}
                    </Link>
                </p>

                <article className="space-y-3">
                    <h1 className="text-2xl font-semibold">{topic.name}</h1>
                    <div className="flex items-center gap-2 text-sm text-muted-foreground">
                        <Avatar id={topic.author?.id ?? 0} name={topic.author?.name ?? ''} src={topic.author?.imageUrl ?? null} size="sm" />
                        {topic.author ? (
                            <Link href={`/m/member/${topic.author.id}`} className="hover:underline">
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
                            <Link href={`/m/community/topic/${topic.id}/edit`} className="hover:underline">
                                {t('Edit')}
                            </Link>
                            <button type="button" onClick={deleteTopic} className="text-red-600 hover:underline">
                                {t('Delete')}
                            </button>
                        </div>
                    )}
                </article>

                <section className="space-y-3">
                    <h2 className="text-lg font-semibold">{t(':count comments', { count: comments.length })}</h2>
                    {comments.length === 0 ? (
                        <p className="text-sm text-muted-foreground">{t('No comments yet.')}</p>
                    ) : (
                        <ul className="space-y-3">
                            {comments.map((comment) => (
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
                    <form onSubmit={submitComment} className="space-y-2">
                        <h2 className="text-lg font-semibold">{t('Post a comment')}</h2>
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
                        <button
                            type="submit"
                            disabled={form.processing || form.data.body.trim() === ''}
                            className="min-h-11 rounded-full bg-blue-600 px-5 text-sm font-medium text-white transition hover:bg-blue-700 disabled:opacity-50"
                        >
                            {t('Post comment')}
                        </button>
                    </form>
                )}
            </main>
        </>
    );
}
