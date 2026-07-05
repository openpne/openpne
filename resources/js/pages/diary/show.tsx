import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { type FormEvent } from 'react';
import { FlashMessage } from '@/components/flash-message';
import { Button } from '@/components/ui/button';
import { DangerLink } from '@/components/ui/danger-link';
import { Field } from '@/components/ui/field';
import { List, Panel } from '@/components/ui/surface';
import { Textarea } from '@/components/ui/textarea';
import { formatDateTime } from '@/lib/date';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { DiaryComment, DiaryDetail } from './types';

interface ShowProps extends PageProps {
    diary: DiaryDetail;
    comments: DiaryComment[];
}

type GridImage = { id: number; url: string; thumbnailUrl: string };

function ImageGrid({ images, size }: { images: GridImage[]; size: string }) {
    const t = useT();
    if (images.length === 0) {
        return null;
    }

    return (
        <ul className="mt-1 flex flex-wrap gap-2">
            {images.map((image, i) => (
                <li key={image.id}>
                    <a href={image.url} target="_blank" rel="noopener noreferrer" aria-label={`${t('Image')} ${i + 1}`}>
                        <img src={image.thumbnailUrl} alt="" className={`${size} rounded-md object-cover`} />
                    </a>
                </li>
            ))}
        </ul>
    );
}

export default function DiaryShow() {
    const t = useT();
    const { diary, comments, flash, auth } = usePage<ShowProps>().props;
    const isOwner = auth.user?.id === diary.author.id;

    const form = useForm({ body: '', images: [] as File[] });
    const submitComment = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/m/diary/${diary.id}/comment/create`, {
            forceFormData: true,
            onSuccess: () => form.reset('body', 'images'),
        });
    };

    return (
        <>
            <Head title={diary.title} />
            <main className="mx-auto max-w-2xl space-y-4 px-4 py-8 text-foreground">
                <h1 className="break-words text-xl font-semibold">{diary.title}</h1>

                {flash.status && <FlashMessage>{flash.status}</FlashMessage>}
                {flash.error && <FlashMessage variant="error">{flash.error}</FlashMessage>}

                <Panel bodyClassName="space-y-4">
                    <p className="text-sm text-muted-foreground">
                        {diary.author.name} &mdash; {formatDateTime(diary.createdAt)}
                    </p>

                    <div className="whitespace-pre-wrap break-words">{diary.body}</div>

                    <ImageGrid images={diary.images} size="size-28" />

                    {isOwner && (
                        <div className="flex gap-4 text-sm">
                            <Link href={`/m/diary/edit/${diary.id}`} className="text-link hover:underline">
                                {t('Edit')}
                            </Link>
                            <DangerLink href={`/m/diary/deleteConfirm/${diary.id}`}>{t('Delete')}</DangerLink>
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
                                            <DangerLink href={`/m/diary/comment/deleteConfirm/${comment.id}`} className="shrink-0">
                                                {t('Delete')}
                                            </DangerLink>
                                        )}
                                    </div>
                                    <p className="whitespace-pre-wrap break-words">{comment.body}</p>
                                    <ImageGrid images={comment.images} size="size-20" />
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
                            {t('Save')}
                        </Button>
                    </form>
                </Panel>
            </main>
        </>
    );
}
