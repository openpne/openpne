import { LinkCard } from '@/components/link-card';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { type FormEvent } from 'react';
import { Avatar } from '@/components/avatar';
import { useConfirm } from '@/components/confirm-dialog';
import { ImageGrid } from '@/components/image-grid';
import { ImagesField } from '@/components/images-field';
import { RichBody } from '@/components/rich-body';
import { Timestamp } from '@/components/timestamp';
import { Heading } from '@/components/ui/heading';
import { UserText } from '@/components/user-text';
import { Button } from '@/components/ui/button';
import { dangerActionClass } from '@/components/ui/danger-link';
import { Field } from '@/components/ui/field';
import { List, Panel } from '@/components/ui/surface';
import { Textarea } from '@/components/ui/textarea';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';
import type { DiaryComment, DiaryDetail, DiaryNeighbor } from './types';

interface ShowProps extends PageProps {
    diary: DiaryDetail;
    comments: DiaryComment[];
    older: DiaryNeighbor | null; // older entry by the same author
    newer: DiaryNeighbor | null; // newer entry by the same author
}

export default function DiaryShow() {
    const t = useT();
    const confirm = useConfirm();
    const { diary, comments, older, newer, auth } = usePage<ShowProps>().props;
    const isOwner = auth.user?.id === diary.author.id;

    const form = useForm({ body: '', images: [] as File[] });
    const submitComment = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/diary/${diary.id}/comment/create`, {
            forceFormData: true,
            onSuccess: () => form.reset('body', 'images'),
        });
    };

    const deleteDiary = async () => {
        if (await confirm({ title: t('Delete this %diary%?'), description: diary.title, confirmLabel: t('Delete'), danger: true })) {
            router.post(`/diary/delete/${diary.id}`);
        }
    };

    const deleteComment = async (commentId: number) => {
        if (await confirm({ title: t('Delete this comment?'), confirmLabel: t('Delete'), danger: true })) {
            router.post(`/diary/comment/delete/${commentId}`, {}, { preserveScroll: true });
        }
    };

    return (
        <>
            <Head title={diary.title} />
            <Heading variant="page">{diary.title}</Heading>

            <Panel bodyClassName="space-y-4">
                <div className="flex items-center gap-2 text-sm text-muted-foreground">
                    <Avatar id={diary.author.id} name={diary.author.name} src={diary.author.imageUrl} color={diary.author.avatarColor} size="sm" decorative />
                    <Link href={`/member/${diary.author.id}`} className="text-link hover:underline">
                        {diary.author.name}
                    </Link>
                    <span>&mdash; <Timestamp at={diary.createdAt} preset="absolute" /></span>
                </div>

                <RichBody body={diary.body} bodyHtml={diary.bodyHtml} />

                <LinkCard card={diary.linkCard} />

                    <ImageGrid images={diary.images} size="size-28" className="mt-1" />

                {isOwner && (
                    <div className="flex gap-4 text-sm">
                        <Link href={`/diary/edit/${diary.id}`} className="text-link hover:underline">
                            {t('Edit')}
                        </Link>
                        <button type="button" onClick={deleteDiary} className={dangerActionClass}>
                            {t('Delete')}
                        </button>
                    </div>
                )}
            </Panel>

            {(older || newer) && (
                <nav className="flex items-center justify-between gap-3" aria-label={t('%Diary% navigation')}>
                    {older ? (
                        <Link href={`/diary/${older.id}`} className="group flex min-h-11 min-w-0 flex-1 items-center gap-1.5">
                            <ChevronLeft className="size-4 shrink-0 text-link" aria-hidden />
                            <span className="min-w-0">
                                <span className="block text-xs text-muted-foreground">{t('Older %Diary%')}</span>
                                <span className="block truncate text-sm text-link group-hover:underline">{older.title}</span>
                            </span>
                        </Link>
                    ) : (
                        <span className="flex-1" />
                    )}
                    {newer ? (
                        <Link href={`/diary/${newer.id}`} className="group flex min-h-11 min-w-0 flex-1 items-center justify-end gap-1.5 text-right">
                            <span className="min-w-0">
                                <span className="block text-xs text-muted-foreground">{t('Newer %Diary%')}</span>
                                <span className="block truncate text-sm text-link group-hover:underline">{newer.title}</span>
                            </span>
                            <ChevronRight className="size-4 shrink-0 text-link" aria-hidden />
                        </Link>
                    ) : (
                        <span className="flex-1" />
                    )}
                </nav>
            )}

            {comments.length > 0 && (
                <Panel title={t('Comments')} flush>
                    <List>
                        {comments.map((comment) => (
                            <li key={comment.id} className="space-y-2 px-4 py-4 sm:px-5">
                                {/* Flex header (not inline prose) — inline text-link inside a muted
                                    text block trips axe link-in-text-block; this also matches the
                                    topic/event comment header shape. */}
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
                                    <Timestamp at={comment.createdAt} preset="relative" className="shrink-0" />
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

            {/* The thread is readable on a web-public entry; commenting needs an account. */}
            {auth.user && (
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
            )}
        </>
    );
}
