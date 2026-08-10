import { LinkCard } from '@/components/link-card';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { ImageGrid } from '@/components/image-grid';
import { Avatar } from '@/components/avatar';
import { useConfirm } from '@/components/confirm-dialog';
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
import type { TimelinePostEntry } from './types';

interface ShowProps extends PageProps {
    post: TimelinePostEntry;
    replies: TimelinePostEntry[];
    viewerId: number;
}

export default function TimelineShow() {
    const t = useT();
    const confirm = useConfirm();
    const { post, replies, viewerId } = usePage<ShowProps>().props;
    // The tab title keeps the author context; the on-screen h1 is generic — the author's name is
    // already in the crumb above and on the post card below.
    const headTitle = t(":name's %activity%", { name: post.author.name });
    const form = useForm({ body: '' });

    const submitReply = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/timeline/${post.id}/reply`, { onSuccess: () => form.reset('body') });
    };

    const deletePost = async () => {
        if (await confirm({ title: t('Delete this post?'), confirmLabel: t('Delete'), danger: true })) {
            router.post(`/timeline/delete/${post.id}`);
        }
    };

    const deleteReply = async (replyId: number) => {
        if (await confirm({ title: t('Delete this reply?'), confirmLabel: t('Delete'), danger: true })) {
            router.post(`/timeline/delete/${replyId}`, {}, { preserveScroll: true });
        }
    };

    return (
        <>
            <Head title={headTitle} />
            <Heading variant="page">{t('Post detail')}</Heading>

            <Panel bodyClassName="space-y-2">
                <div className="flex items-center justify-between gap-3 text-sm">
                    <Link href={`/member/${post.author.id}/timeline`} className="flex min-w-0 items-center gap-2 text-link hover:underline">
                        <Avatar id={post.author.id} name={post.author.name} src={post.author.imageUrl} color={post.author.avatarColor} size="sm" decorative />
                        <span className="truncate">{post.author.name}</span>
                    </Link>
                    <Timestamp at={post.createdAt} preset="absolute" className="shrink-0 text-muted-foreground" />
                </div>
                <p className="whitespace-pre-wrap break-words">
                    <UserText text={post.body} />
                </p>
                <LinkCard card={post.linkCard} />
                <ImageGrid images={post.images} />
                {post.author.id === viewerId && (
                    <button type="button" onClick={deletePost} className={cn(dangerActionClass, 'text-sm')}>
                        {t('Delete')}
                    </button>
                )}
            </Panel>

            {replies.length > 0 && (
                <Panel flush>
                    <List>
                        {replies.map((reply) => (
                            <li key={reply.id} className="space-y-1 px-4 py-3 sm:px-5">
                                <div className="flex items-center justify-between text-sm">
                                    <Link href={`/member/${reply.author.id}/timeline`} className="text-link hover:underline">
                                        {reply.author.name}
                                    </Link>
                                    <Timestamp at={reply.createdAt} preset="listStamp" className="text-muted-foreground" />
                                </div>
                                <p className="whitespace-pre-wrap break-words">
                                    <UserText text={reply.body} />
                                </p>
                                {reply.author.id === viewerId && (
                                    <button type="button" onClick={() => deleteReply(reply.id)} className={cn(dangerActionClass, 'text-sm')}>
                                        {t('Delete')}
                                    </button>
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
        </>
    );
}
