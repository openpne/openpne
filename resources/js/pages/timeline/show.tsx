import { LinkCard } from '@/components/link-card';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useId, type FormEvent } from 'react';
import { ImageGrid } from '@/components/image-grid';
import { MentionTextarea } from '@/components/compose/mention-textarea';
import { Avatar } from '@/components/avatar';
import { useConfirm } from '@/components/confirm-dialog';
import { Timestamp } from '@/components/timestamp';
import { Heading } from '@/components/ui/heading';
import { EntityText } from '@/components/entity-text';
import { Button } from '@/components/ui/button';
import { dangerActionClass } from '@/components/ui/danger-link';
import { Field } from '@/components/ui/field';
import { List, Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import { toPayload, type DraftMention } from '@/lib/mention-draft';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';
import { BodyCounter, overBodyLimit } from './body-counter';
import type { TimelinePostEntry } from './types';

interface ShowProps extends PageProps {
    post: TimelinePostEntry;
    replies: TimelinePostEntry[];
    viewerId: number;
    canReply: boolean;
    /** Set when the thread belongs to a group: the chrome and the mention picker follow it. */
    group: { id: number; name: string } | null;
}

export default function TimelineShow() {
    const t = useT();
    const confirm = useConfirm();
    const { post, replies, viewerId, canReply, group } = usePage<ShowProps>().props;
    // The tab title keeps the author context; the on-screen h1 is generic — the author's name is
    // already in the crumb above and on the post card below.
    const headTitle = t(":name's %activity%", { name: post.author.name });
    const counterId = useId();
    const form = useForm({ body: '', mentions: [] as DraftMention[] });

    const submitReply = (e: FormEvent) => {
        e.preventDefault();
        // See timeline/new.tsx: the draft is UTF-16 offsets, the request is code-point ranges.
        form.transform((data) => ({ ...data, mentions: toPayload(data.mentions, data.body) }));
        form.post(`/timeline/${post.id}/reply`, { onSuccess: () => form.reset('body', 'mentions') });
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
                        <Avatar id={post.author.id} name={post.author.name} src={post.author.imageUrl} color={post.author.avatarColor} size="md" decorative />
                        <span className="truncate">{post.author.name}</span>
                    </Link>
                    <Timestamp at={post.createdAt} preset="absolute" className="shrink-0 text-muted-foreground" />
                </div>
                <p className="whitespace-pre-wrap break-words">
                    <EntityText text={post.body} mentions={post.mentions} tags={post.tags} />
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
                                    <Timestamp at={reply.createdAt} preset="relative" className="text-muted-foreground" />
                                </div>
                                <p className="whitespace-pre-wrap break-words">
                                    <EntityText text={reply.body} mentions={reply.mentions} tags={reply.tags} />
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

            {/* Reading a group thread does not admit someone to it: an everyone-readable
                group is open to any member, but only its own may reply. */}
            {canReply && (
            <Panel overflow="visible">
                <form onSubmit={submitReply} className="space-y-2">
                    <Field
                        label={t('Reply')}
                        htmlFor="reply_body"
                        error={form.errors.body}
                        labelRight={<BodyCounter id={counterId} body={form.data.body} />}
                    >
                        <MentionTextarea
                            id="reply_body"
                            required
                            rows={3}
                            aria-describedby={counterId}
                            value={form.data.body}
                            onChange={(body) => form.setData('body', body)}
                            mentions={form.data.mentions}
                            onMentionsChange={(mentions) => form.setData('mentions', mentions)}
                            candidatesUrl={group ? `/timeline/mention-candidates?community=${group.id}` : undefined}
                        />
                    </Field>
                    <Button type="submit" loading={form.processing} disabled={form.data.body.trim() === '' || overBodyLimit(form.data.body)}>
                        {t('Reply')}
                    </Button>
                </form>
            </Panel>
            )}
        </>
    );
}
