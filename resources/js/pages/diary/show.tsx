import { AiChip } from '@/components/ai-chip';
import { LinkCard } from '@/components/link-card';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { type FormEvent } from 'react';
import { Avatar } from '@/components/avatar';
import { useConfirm } from '@/components/confirm-dialog';
import { ImageGrid } from '@/components/image-grid';
import { ImagesField } from '@/components/images-field';
import { DetailReactionChips } from '@/components/reactions/reaction-bar';
import { RowSheetHost } from '@/components/row/row-sheet-host';
import { useRowSheet } from '@/components/row/use-row-sheet';
import { ReactorsDialog } from '@/components/reactions/reactors-dialog';
import { RichBody } from '@/components/rich-body';
import { Timestamp } from '@/components/timestamp';
import { Heading } from '@/components/ui/heading';
import { Button } from '@/components/ui/button';
import { dangerActionClass } from '@/components/ui/danger-link';
import { Field } from '@/components/ui/field';
import { List, Panel } from '@/components/ui/surface';
import { Textarea } from '@/components/ui/textarea';
import { commentsPhrase } from '@/lib/count-phrase';
import { useT } from '@/lib/i18n';
import { rowReactions } from '@/lib/reactions/row';
import { useReactions } from '@/lib/reactions/use-reactions';
import type { PageProps } from '@/types';
import { DiaryCommentRow } from './comment-row';
import { diaryCommentReactionEndpoints, diaryReactionEndpoints } from './reactions';
import { diaryThreadLink } from './thread-link';
import type { DiaryDetail, DiaryNeighbor, DiaryThread } from './types';

interface ShowProps extends PageProps {
    diary: DiaryDetail;
    thread: DiaryThread;
    older: DiaryNeighbor | null; // older entry by the same author
    newer: DiaryNeighbor | null; // newer entry by the same author
    reactionVocabulary: string[];
    /** Fresh per render: a comment posted or deleted re-renders the page under the same URL. */
    renderGeneration: string;
}

export default function DiaryShow() {
    const t = useT();
    const confirm = useConfirm();
    const { diary, thread, older, newer, auth, reactionVocabulary, renderGeneration } = usePage<ShowProps>().props;
    const isOwner = auth.user?.id === diary.author.id;
    // Two rows of state, one per endpoint set: the entry and its comments are reacted to on different URLs.
    const diaryReactions = useReactions(diaryReactionEndpoints, renderGeneration);
    const commentReactions = useReactions(diaryCommentReactionEndpoints, renderGeneration);
    const canReact = auth.user !== null;
    const sheet = useRowSheet();
    const threadLink = (page: number, ascending: boolean) => diaryThreadLink(diary.id, thread.size, page, ascending);

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

    const deleteComment = async (commentId: number, opener: HTMLElement | null = null) => {
        if (await confirm({ title: t('Delete this comment?'), confirmLabel: t('Delete'), danger: true, opener })) {
            router.post(`/diary/comment/delete/${commentId}`, {}, { preserveScroll: true });
        }
    };

    return (
        <>
            <Head title={diary.title} />
            <Heading variant="page">{diary.title}</Heading>

            <Panel bodyClassName="space-y-4">
                <div className="flex items-center gap-2 text-sm text-muted-foreground">
                    <Avatar id={diary.author.id} name={diary.author.name} src={diary.author.imageUrl} color={diary.author.avatarColor} isAi={diary.author.isAi} size="md" decorative />
                    <Link href={`/member/${diary.author.id}`} className="text-link hover:underline">
                        {diary.author.name}
                    </Link>
                    <AiChip isAi={diary.author.isAi} />
                    <span>&mdash; <Timestamp at={diary.createdAt} preset="absolute" /></span>
                </div>

                <RichBody body={diary.body} bodyHtml={diary.bodyHtml} />

                <LinkCard card={diary.linkCard} />

                <ImageGrid images={diary.images} variant="post" className="mt-1" />

                <DetailReactionChips reactions={rowReactions(diary.id, diary.reactions, reactionVocabulary, canReact ? diaryReactions : null)} />

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

            {thread.total > 0 && (
                <Panel title={commentsPhrase(t, thread.total)} flush>
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
                    <List>
                        {thread.comments.map((comment) => (
                            <DiaryCommentRow
                                key={comment.id}
                                comment={comment}
                                onDelete={deleteComment}
                                reactions={rowReactions(comment.id, comment.reactions, reactionVocabulary, canReact ? commentReactions : null)}
                                onOpenActions={(row) => sheet.open(comment.id, row)}
                            />
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

            {diaryReactions.reactorsFor !== null && <ReactorsDialog url={diaryReactions.reactorsUrl(diaryReactions.reactorsFor)} emoji={diaryReactions.reactorsEmoji} returnFocusTo={diaryReactions.reactorsOpener} onClose={diaryReactions.closeReactors} />}
            {commentReactions.reactorsFor !== null && <ReactorsDialog url={commentReactions.reactorsUrl(commentReactions.reactorsFor)} emoji={commentReactions.reactorsEmoji} returnFocusTo={commentReactions.reactorsOpener} onClose={commentReactions.closeReactors} />}
            <RowSheetHost
                sheet={sheet}
                spec={(id) => {
                    const comment = thread.comments.find((candidate) => candidate.id === id);
                    if (comment === undefined) {
                        return null;
                    }

                    return {
                        body: comment.body,
                        author: comment.author,
                        createdAt: comment.createdAt,
                        chips: canReact ? commentReactions.chips(comment.id, comment.reactions) : comment.reactions,
                        vocabulary: reactionVocabulary,
                        canReact,
                        onToggle: (emoji, mine) => commentReactions.toggle(comment.id, emoji, mine),
                        // The names are read on a route a guest cannot reach, so a guest is not offered them.
                        onShowReactors: canReact ? (opener) => commentReactions.showReactors(comment.id, undefined, opener) : undefined,
                        onDelete: comment.deletable ? (opener) => void deleteComment(comment.id, opener) : undefined,
                    };
                }}
            />
        </>
    );
}
