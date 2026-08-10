import { LinkCard } from '@/components/link-card';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { type FormEvent } from 'react';
import { ImageGrid } from '@/components/image-grid';
import { ImagesField } from '@/components/images-field';
import { Avatar } from '@/components/avatar';
import { useConfirm } from '@/components/confirm-dialog';
import { RichBody } from '@/components/rich-body';
import { Timestamp } from '@/components/timestamp';
import { Heading } from '@/components/ui/heading';
import { UserText } from '@/components/user-text';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { dangerActionClass } from '@/components/ui/danger-link';
import { List, Panel } from '@/components/ui/surface';
import { Textarea } from '@/components/ui/textarea';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { CommunitySummary, TopicDetail, TopicThread } from '../types';

interface ShowProps extends PageProps {
    community: CommunitySummary;
    topic: TopicDetail;
    thread: TopicThread;
    canComment: boolean;
    canEdit: boolean;
}

export default function CommunityTopicShow() {
    const t = useT();
    const confirm = useConfirm();
    const { topic, thread, canComment, canEdit } = usePage<ShowProps>().props;

    // Mirror the OpenPNE 3 pager URL: order dropped when default (desc), page dropped when 1.
    const threadLink = (page: number, ascending: boolean) => {
        const params = new URLSearchParams();
        if (ascending) params.set('order', 'asc');
        if (page > 1) params.set('page', String(page));
        const qs = params.toString();
        return `/communityTopic/${topic.id}${qs ? `?${qs}` : ''}`;
    };

    const form = useForm({ body: '', images: [] as File[] });
    const submitComment = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/communityTopic/${topic.id}/comment/create`, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => form.reset('body', 'images'),
        });
    };

    const deleteTopic = async () => {
        if (await confirm({ title: t('Delete this %topic%?'), description: topic.name, confirmLabel: t('Delete'), danger: true })) {
            router.post(`/communityTopic/delete/${topic.id}`);
        }
    };

    const deleteComment = async (commentId: number) => {
        if (await confirm({ title: t('Delete this comment?'), confirmLabel: t('Delete'), danger: true })) {
            router.post(`/communityTopic/comment/delete/${commentId}`, {}, { preserveScroll: true });
        }
    };

    return (
        <>
            <Head title={topic.name} />

            <Heading variant="page">{topic.name}</Heading>

            <Panel bodyClassName="space-y-3">
                <div className="flex items-center gap-2 text-sm text-muted-foreground">
                    <Avatar id={topic.author?.id ?? 0} name={topic.author?.name ?? ''} src={topic.author?.imageUrl ?? null} color={topic.author?.avatarColor ?? null} size="sm" decorative />
                    {topic.author ? (
                        <Link href={`/member/${topic.author.id}`} className="text-link hover:underline">
                            {topic.author.name}
                        </Link>
                    ) : (
                        <span>{t('Withdrawn member')}</span>
                    )}
                    <span>&mdash; <Timestamp at={topic.createdAt} preset="absolute" /></span>
                </div>

                <RichBody body={topic.body} bodyHtml={topic.bodyHtml} />
                <LinkCard card={topic.linkCard} />
                    <ImageGrid images={topic.images} size="size-24" className="mt-2" />

                {canEdit && (
                    <div className="flex gap-4 text-sm">
                        <Link href={`/communityTopic/edit/${topic.id}`} className="text-link hover:underline">
                            {t('Edit')}
                        </Link>
                        <button type="button" onClick={deleteTopic} className={dangerActionClass}>
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
                                    <span className="ml-auto shrink-0">#{comment.number}</span>
                                    <Timestamp at={comment.createdAt} preset="absolute" className="shrink-0" />
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
                <Panel title={t('Post a comment')}>
                    <form onSubmit={submitComment} className="space-y-3">
                        <Field label={t('Comment')} htmlFor="comment_body" error={form.errors.body}>
                            <Textarea id="comment_body" required rows={5} value={form.data.body} onChange={(e) => form.setData('body', e.target.value)} />
                        </Field>
                        <ImagesField id="comment_images" label={t('Images')} files={form.data.images} onChange={(files) => form.setData('images', files)} errors={form.errors} />
                        <Button type="submit" loading={form.processing} disabled={form.data.body.trim() === ''}>
                            {t('Post comment')}
                        </Button>
                    </form>
                </Panel>
            )}
        </>
    );
}
