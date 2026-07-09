import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { type FormEvent } from 'react';
import { Avatar } from '@/components/avatar';
import { useConfirm } from '@/components/confirm-dialog';
import { ImageGrid } from '@/components/image-grid';
import { ImagesField } from '@/components/images-field';
import { UserText } from '@/components/user-text';
import { Button } from '@/components/ui/button';
import { dangerActionClass } from '@/components/ui/danger-link';
import { Field } from '@/components/ui/field';
import { List, Panel } from '@/components/ui/surface';
import { Textarea } from '@/components/ui/textarea';
import { formatDateTime } from '@/lib/date';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';
import type { DiaryComment, DiaryDetail } from './types';

interface ShowProps extends PageProps {
    diary: DiaryDetail;
    comments: DiaryComment[];
}

export default function DiaryShow() {
    const t = useT();
    const confirm = useConfirm();
    const { diary, comments, auth } = usePage<ShowProps>().props;
    const isOwner = auth.user?.id === diary.author.id;

    const form = useForm({ body: '', images: [] as File[] });
    const submitComment = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/m/diary/${diary.id}/comment/create`, {
            forceFormData: true,
            onSuccess: () => form.reset('body', 'images'),
        });
    };

    const deleteDiary = async () => {
        if (await confirm({ title: t('Delete this %diary%?'), description: diary.title, confirmLabel: t('Delete'), danger: true })) {
            router.post(`/m/diary/delete/${diary.id}`);
        }
    };

    const deleteComment = async (commentId: number) => {
        if (await confirm({ title: t('Delete this comment?'), confirmLabel: t('Delete'), danger: true })) {
            router.post(`/m/diary/comment/delete/${commentId}`, {}, { preserveScroll: true });
        }
    };

    return (
        <>
            <Head title={diary.title} />
            <h1 className="break-words text-xl font-semibold">{diary.title}</h1>

            <Panel bodyClassName="space-y-4">
                <div className="flex items-center gap-2 text-sm text-muted-foreground">
                    <Avatar id={diary.author.id} name={diary.author.name} src={diary.author.imageUrl} size="sm" decorative />
                    <Link href={`/m/member/${diary.author.id}`} className="text-link hover:underline">
                        {diary.author.name}
                    </Link>
                    <span>&mdash; {formatDateTime(diary.createdAt)}</span>
                </div>

                <div className="whitespace-pre-wrap break-words">
                    <UserText text={diary.body} />
                </div>

                <ImageGrid images={diary.images} size="size-28" className="mt-1" />

                {isOwner && (
                    <div className="flex gap-4 text-sm">
                        <Link href={`/m/diary/edit/${diary.id}`} className="text-link hover:underline">
                            {t('Edit')}
                        </Link>
                        <button type="button" onClick={deleteDiary} className={dangerActionClass}>
                            {t('Delete')}
                        </button>
                    </div>
                )}
            </Panel>

            {comments.length > 0 && (
                <Panel title={t('Comments')} flush>
                    <List>
                        {comments.map((comment) => (
                            <li key={comment.id} className="space-y-2 px-5 py-4">
                                {/* Flex header (not inline prose) — inline text-link inside a muted
                                    text block trips axe link-in-text-block; this also matches the
                                    topic/event comment header shape. */}
                                <div className="flex items-baseline gap-2 text-sm text-muted-foreground">
                                    <span className="font-medium">#{comment.number}</span>
                                    {comment.author ? (
                                        <Link href={`/m/member/${comment.author.id}`} className="truncate text-link hover:underline">
                                            {comment.author.name}
                                        </Link>
                                    ) : (
                                        <span>{t('Withdrawn member')}</span>
                                    )}
                                    <span className="ml-auto shrink-0">{formatDateTime(comment.createdAt)}</span>
                                    {comment.deletable && (
                                        <button type="button" onClick={() => deleteComment(comment.id)} className={cn(dangerActionClass, 'shrink-0')}>
                                            {t('Delete')}
                                        </button>
                                    )}
                                </div>
                                <p className="whitespace-pre-wrap break-words">
                                    <UserText text={comment.body} />
                                </p>
                                <ImageGrid images={comment.images} size="size-20" className="mt-1" />
                            </li>
                        ))}
                    </List>
                </Panel>
            )}

            <Panel title={t('Post a comment')}>
                <form onSubmit={submitComment} className="space-y-3">
                    {diary.visibility === 'open' && (
                        <p className="text-sm text-muted-foreground">{t('Your comment is visible to everyone on the web.')}</p>
                    )}
                    <Field label={t('Comment')} htmlFor="comment_body" error={form.errors.body}>
                        <Textarea id="comment_body" required rows={8} value={form.data.body} onChange={(e) => form.setData('body', e.target.value)} />
                    </Field>
                    <ImagesField id="comment_images" label={t('Images')} files={form.data.images} onChange={(files) => form.setData('images', files)} errors={form.errors} />
                    <Button type="submit" loading={form.processing} disabled={form.data.body.trim() === ''}>
                        {t('Save')}
                    </Button>
                </form>
            </Panel>
        </>
    );
}
