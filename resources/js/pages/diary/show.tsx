import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { type FormEvent } from 'react';
import { FlashMessage } from '@/components/flash-message';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Textarea } from '@/components/ui/textarea';
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
                <h1 className="text-xl font-semibold">{diary.title}</h1>

                {flash.status && <FlashMessage>{flash.status}</FlashMessage>}
                {flash.error && <FlashMessage variant="error">{flash.error}</FlashMessage>}

                <p className="text-sm text-muted-foreground">
                    {diary.author.name} &mdash; {new Date(diary.createdAt).toLocaleString()}
                </p>

                <div className="whitespace-pre-wrap break-words">{diary.body}</div>

                <ImageGrid images={diary.images} size="size-28" />

                {isOwner && (
                    <div className="flex gap-4 text-sm">
                        <Link href={`/m/diary/edit/${diary.id}`} className="text-link hover:underline">
                            {t('Edit')}
                        </Link>
                        <Link href={`/m/diary/deleteConfirm/${diary.id}`} className="text-link hover:underline">
                            {t('Delete')}
                        </Link>
                    </div>
                )}

                {comments.length > 0 && (
                    <section className="space-y-3">
                        <h2 className="text-lg font-semibold">{t('Comments')}</h2>
                        <ul className="space-y-3">
                            {comments.map((comment) => (
                                <li key={comment.id} className="border-t border-border pt-3">
                                    <p className="text-sm text-muted-foreground">
                                        <strong>{comment.number}</strong>:{' '}
                                        {comment.author ? (
                                            <Link href={`/m/member/${comment.author.id}`} className="text-link hover:underline">
                                                {comment.author.name}
                                            </Link>
                                        ) : (
                                            t('Withdrawn member')
                                        )}{' '}
                                        &mdash; {new Date(comment.createdAt).toLocaleString()}
                                        {comment.deletable && (
                                            <>
                                                {' '}
                                                <Link href={`/m/diary/comment/deleteConfirm/${comment.id}`} className="text-link hover:underline">
                                                    {t('Delete')}
                                                </Link>
                                            </>
                                        )}
                                    </p>
                                    <p className="whitespace-pre-wrap break-words">{comment.body}</p>
                                    <ImageGrid images={comment.images} size="size-20" />
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                <form onSubmit={submitComment} className="space-y-3">
                    <h2 className="text-lg font-semibold">{t('Post a comment')}</h2>
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
            </main>
        </>
    );
}
