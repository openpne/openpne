import { Head, Link, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { TimelinePostEntry } from './types';

interface ShowProps extends PageProps {
    post: TimelinePostEntry;
    replies: TimelinePostEntry[];
    viewerId: number;
}

export default function TimelineShow() {
    const t = useT();
    const { post, replies, viewerId, flash } = usePage<ShowProps>().props;
    const title = t(":name's %activity%", { name: post.author.name });
    const form = useForm({ body: '' });

    const submitReply = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/m/timeline/${post.id}/reply`, { onSuccess: () => form.reset('body') });
    };

    return (
        <>
            <Head title={title} />
            <main className="mx-auto max-w-2xl space-y-4 px-4 py-8">
                <h1 className="text-2xl font-semibold">{title}</h1>

                {flash.status && <p role="status">{flash.status}</p>}

                <article className="space-y-2 border-b pb-4">
                    <div className="flex items-center justify-between text-sm">
                        <Link href={`/m/member/${post.author.id}/timeline`} className="font-medium hover:underline">
                            {post.author.name}
                        </Link>
                        <span className="text-muted-foreground">{new Date(post.createdAt).toLocaleString()}</span>
                    </div>
                    <p className="whitespace-pre-wrap">{post.body}</p>
                    {post.images.length > 0 && (
                        <div className="flex flex-wrap gap-2">
                            {post.images.map((image) => (
                                <img key={image.id} src={image.thumbnailUrl} alt="" className="rounded" />
                            ))}
                        </div>
                    )}
                    {post.author.id === viewerId && (
                        <Link href={`/m/timeline/deleteConfirm/${post.id}`} className="text-sm hover:underline">
                            {t('Delete')}
                        </Link>
                    )}
                </article>

                {replies.length > 0 && (
                    <ul className="space-y-3">
                        {replies.map((reply) => (
                            <li key={reply.id} className="space-y-1 border-b pb-3">
                                <div className="flex items-center justify-between text-sm">
                                    <Link href={`/m/member/${reply.author.id}/timeline`} className="font-medium hover:underline">
                                        {reply.author.name}
                                    </Link>
                                    <span className="text-muted-foreground">{new Date(reply.createdAt).toLocaleString()}</span>
                                </div>
                                <p className="whitespace-pre-wrap">{reply.body}</p>
                                {reply.author.id === viewerId && (
                                    <Link href={`/m/timeline/deleteConfirm/${reply.id}`} className="text-sm hover:underline">
                                        {t('Delete')}
                                    </Link>
                                )}
                            </li>
                        ))}
                    </ul>
                )}

                <form onSubmit={submitReply} className="space-y-2">
                    <label htmlFor="reply_body">{t('Reply')}</label>
                    <textarea
                        id="reply_body"
                        value={form.data.body}
                        onChange={(e) => form.setData('body', e.target.value)}
                        required
                        maxLength={140}
                        rows={3}
                    />
                    {form.errors.body && <p role="alert">{form.errors.body}</p>}
                    <button type="submit" disabled={form.processing}>
                        {t('Reply')}
                    </button>
                </form>

                <Link href={`/m/member/${post.author.id}/timeline`} className="hover:underline">
                    {t(":name's %activity%", { name: post.author.name })}
                </Link>
            </main>
        </>
    );
}
