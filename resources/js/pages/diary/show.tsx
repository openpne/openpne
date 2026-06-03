import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { DiaryComment, DiaryDetail } from './types';

interface ShowProps extends PageProps {
    diary: DiaryDetail;
    comments: DiaryComment[];
}

export default function DiaryShow() {
    const t = useT();
    const { diary, comments, flash, auth } = usePage<ShowProps>().props;
    const isOwner = auth.user?.id === diary.author.id;

    const form = useForm({ body: '' });
    const submitComment = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(`/m/diary/${diary.id}/comment/create`, { onSuccess: () => form.reset('body') });
    };

    return (
        <>
            <Head title={diary.title} />
            <main className="mx-auto max-w-2xl space-y-4 px-4 py-8">
                <h1 className="text-2xl font-semibold">{diary.title}</h1>

                {flash.status && <p role="status">{flash.status}</p>}
                {flash.error && <p role="alert">{flash.error}</p>}

                <p className="text-sm text-muted-foreground">
                    {diary.author.name} &mdash; {new Date(diary.createdAt).toLocaleString()}
                </p>

                <div className="whitespace-pre-wrap">{diary.body}</div>

                {isOwner && (
                    <div className="flex gap-4 text-sm">
                        <Link href={`/m/diary/edit/${diary.id}`} className="hover:underline">
                            {t('Edit')}
                        </Link>
                        <Link href={`/m/diary/deleteConfirm/${diary.id}`} className="hover:underline">
                            {t('Delete')}
                        </Link>
                    </div>
                )}

                {comments.length > 0 && (
                    <section className="space-y-3">
                        <h2 className="text-lg font-semibold">{t('Comments')}</h2>
                        <ul className="space-y-3">
                            {comments.map((comment) => (
                                <li key={comment.id} className="border-t pt-3">
                                    <p className="text-sm text-muted-foreground">
                                        <strong>{comment.number}</strong>:{' '}
                                        {comment.author ? (
                                            <Link href={`/m/member/${comment.author.id}`} className="hover:underline">
                                                {comment.author.name}
                                            </Link>
                                        ) : (
                                            t('Withdrawn member')
                                        )}{' '}
                                        &mdash; {new Date(comment.createdAt).toLocaleString()}
                                        {comment.deletable && (
                                            <>
                                                {' '}
                                                <Link
                                                    href={`/m/diary/comment/deleteConfirm/${comment.id}`}
                                                    className="hover:underline"
                                                >
                                                    {t('Delete')}
                                                </Link>
                                            </>
                                        )}
                                    </p>
                                    <p className="whitespace-pre-wrap">{comment.body}</p>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                <form onSubmit={submitComment} className="space-y-2">
                    <h2 className="text-lg font-semibold">{t('Post a comment')}</h2>
                    {diary.visibility === 'open' && <p>{t('Your comment is visible to everyone on the web.')}</p>}
                    <label htmlFor="comment_body">{t('Comment')}</label>
                    <textarea
                        id="comment_body"
                        value={form.data.body}
                        onChange={(e) => form.setData('body', e.target.value)}
                        required
                        rows={8}
                    />
                    {form.errors.body && <p role="alert">{form.errors.body}</p>}
                    <button type="submit" disabled={form.processing}>
                        {t('Save')}
                    </button>
                </form>
            </main>
        </>
    );
}
