import { Head, Link, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Avatar } from '@/components/avatar';
import { FlashMessage } from '@/components/flash-message';
import { Button } from '@/components/ui/button';
import { DangerLink } from '@/components/ui/danger-link';
import { Field } from '@/components/ui/field';
import { List, Panel } from '@/components/ui/surface';
import { Textarea } from '@/components/ui/textarea';
import { formatDateTime } from '@/lib/date';
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
            <main className="mx-auto max-w-2xl space-y-4 px-4 py-8 text-foreground">
                <h1 className="text-xl font-semibold">{title}</h1>

                {flash.status && <FlashMessage>{flash.status}</FlashMessage>}

                <Panel bodyClassName="space-y-2">
                    <div className="flex items-center justify-between gap-3 text-sm">
                        <Link href={`/m/member/${post.author.id}/timeline`} className="flex min-w-0 items-center gap-2 font-medium text-link hover:underline">
                            <Avatar id={post.author.id} name={post.author.name} src={post.author.imageUrl} size="sm" decorative />
                            <span className="truncate">{post.author.name}</span>
                        </Link>
                        <span className="shrink-0 text-muted-foreground">{formatDateTime(post.createdAt)}</span>
                    </div>
                    <p className="whitespace-pre-wrap break-words">{post.body}</p>
                    {post.images.length > 0 && (
                        <div className="flex flex-wrap gap-2">
                            {post.images.map((image) => (
                                <img key={image.id} src={image.thumbnailUrl} alt="" className="rounded-md" />
                            ))}
                        </div>
                    )}
                    {post.author.id === viewerId && (
                        <DangerLink href={`/m/timeline/deleteConfirm/${post.id}`} className="text-sm">
                            {t('Delete')}
                        </DangerLink>
                    )}
                </Panel>

                {replies.length > 0 && (
                    <Panel flush>
                        <List>
                            {replies.map((reply) => (
                                <li key={reply.id} className="space-y-1 px-5 py-3">
                                    <div className="flex items-center justify-between text-sm">
                                        <Link href={`/m/member/${reply.author.id}/timeline`} className="font-medium text-link hover:underline">
                                            {reply.author.name}
                                        </Link>
                                        <span className="text-muted-foreground">{formatDateTime(reply.createdAt)}</span>
                                    </div>
                                    <p className="whitespace-pre-wrap break-words">{reply.body}</p>
                                    {reply.author.id === viewerId && (
                                        <DangerLink href={`/m/timeline/deleteConfirm/${reply.id}`} className="text-sm">
                                            {t('Delete')}
                                        </DangerLink>
                                    )}
                                </li>
                            ))}
                        </List>
                    </Panel>
                )}

                <Panel>
                    <form onSubmit={submitReply} className="space-y-2">
                        <Field label={t('Reply')} htmlFor="reply_body" error={form.errors.body}>
                            <Textarea id="reply_body" required maxLength={140} rows={3} value={form.data.body} onChange={(e) => form.setData('body', e.target.value)} />
                        </Field>
                        <Button type="submit" loading={form.processing} disabled={form.data.body.trim() === ''}>
                            {t('Reply')}
                        </Button>
                    </form>
                </Panel>

                <Link href={`/m/member/${post.author.id}/timeline`} className="text-sm text-link hover:underline">
                    {t(":name's %activity%", { name: post.author.name })}
                </Link>
            </main>
        </>
    );
}
