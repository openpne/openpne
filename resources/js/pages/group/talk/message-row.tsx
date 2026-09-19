import { Link } from '@inertiajs/react';
import { Copy, Link as LinkIcon, Reply, Trash2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { AiChip } from '@/components/ai-chip';
import { Avatar } from '@/components/avatar';
import { Timestamp } from '@/components/timestamp';
import { EntityText } from '@/components/entity-text';
import { ImageGrid } from '@/components/image-grid';
import { LinkCard } from '@/components/link-card';
import type { RowReactions } from '@/components/reactions/reaction-bar';
import { RowBody } from '@/components/row/row-body';
import { reactorsItem, RowMenu, type RowMenuItem } from '@/components/row/row-menu';
import type { ChatReactionChip } from '@/lib/chat/types';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { canCopyLink, canCopyText, messageLink } from './message-link';
import type { TalkMessage, TalkReplyReference } from './types';

/**
 * The excerpt is bounded server-side (`ChatPreview`); the visible cut is this line's `truncate`.
 * The button carries no `aria-label`: one would override the name built from the sr-only prefix, the
 * author and the excerpt, leaving every reply header reading as the same "go to" button.
 */
function ReplyHeader({ reference, onJump }: { reference: TalkReplyReference; onJump: (parent: { id: number; cursor: string }) => void }) {
    const t = useT();

    /**
     * `top-1/2` puts the arm at the middle of one line, and the reference is one line by
     * construction — let the excerpt wrap and the arm lands at the middle of the block instead.
     */
    const elbow = (
        <span aria-hidden className="relative w-10 shrink-0">
            <span className="absolute top-1/2 -bottom-1 left-1/2 right-0 rounded-tl-md border-t-2 border-l-2 border-muted-foreground/40 transition-colors group-hover/reply:border-muted-foreground motion-reduce:transition-none" />
        </span>
    );

    if (reference.deleted) {
        return (
            <div className="mb-1 flex items-stretch gap-2 text-xs text-muted-foreground">
                {elbow}
                <span className="flex items-center italic">{t('Deleted message')}</span>
            </div>
        );
    }

    return (
        // `cursor-pointer` because this button is a line of muted text: the app's chrome'd buttons
        // keep the arrow they are born with, and this is the exception.
        <button
            type="button"
            onClick={() => onJump({ id: reference.id, cursor: reference.cursor })}
            className="group/reply mb-1 flex w-full cursor-pointer items-stretch gap-2 rounded text-left text-xs text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring motion-reduce:transition-none"
        >
            <span className="sr-only">{t('Go to the replied message')}: </span>
            {elbow}
            <span className="flex min-w-0 flex-1 items-center gap-1.5">
                <span className="shrink-0">{reference.author?.name ?? t('Withdrawn member')}</span>
                {reference.thumbnailUrl !== null && (
                    <img src={reference.thumbnailUrl} alt="" className="size-5 shrink-0 rounded object-cover" />
                )}
                <span className="min-w-0 truncate">{reference.excerpt}</span>
            </span>
        </button>
    );
}

/**
 * The chips are the message's own with whatever tap is still on the wire drawn over them, both owned
 * by the page since a tap outlives the row it was made on.
 */
export interface TalkRowReactions {
    chips: ChatReactionChip[];
    vocabulary: string[];
    /** Reacting is speaking in the room: a reader who may not post sees the chips and cannot move them. */
    canReact: boolean;
    onToggle: (emoji: string, mine: boolean) => void;
    onShowReactors: () => void;
}

const COPIED_MS = 1500;

type Copied = 'text' | 'link';

/**
 * A refusal is answered as well as a success: silence would leave the clipboard's previous contents
 * to read as a copy that worked. The answer lives on the row, since the menu it was chosen from is
 * gone by the time the write settles.
 */
function useCopyAck() {
    const [ack, setAck] = useState<{ what: Copied; outcome: 'copied' | 'failed' } | null>(null);
    const timer = useRef<number | null>(null);
    const mounted = useRef(true);

    // An acknowledgement still pending when the row leaves must not set state on an unmounted
    // row — the write itself can outlive the row, so the flag covers the settle as well as the
    // timeout it would have scheduled.
    useEffect(
        () => () => {
            mounted.current = false;
            if (timer.current !== null) {
                window.clearTimeout(timer.current);
            }
        },
        [],
    );

    const copy = (what: Copied, text: string) => {
        const answer = (outcome: 'copied' | 'failed') => {
            if (!mounted.current) {
                return;
            }
            setAck({ what, outcome });
            if (timer.current !== null) {
                window.clearTimeout(timer.current);
            }
            timer.current = window.setTimeout(() => {
                setAck(null);
                timer.current = null;
            }, COPIED_MS);
        };
        void navigator.clipboard.writeText(text).then(
            () => answer('copied'),
            () => answer('failed'),
        );
    };

    return { ack, copy };
}

export function TalkMessageRow({
    message,
    onDelete,
    onReply,
    onJumpToReply,
    canReply,
    highlighted = false,
    grouped = false,
    separatorAbove = false,
    reactions,
}: {
    message: TalkMessage;
    onDelete: (id: number) => void;
    onReply: () => void;
    onJumpToReply: (parent: { id: number; cursor: string }) => void;
    /** Whether the viewer may post, and so start a reply. Not the message's own fact like canDelete. */
    canReply: boolean;
    highlighted?: boolean;
    grouped?: boolean;
    /** Whether a heading or the unread line stands above this row and already holds its space; such a
     *  row can never be `grouped`. True on the list's first row, always, which keeps a margin off the
     *  first child. */
    separatorAbove?: boolean;
    reactions: TalkRowReactions;
}) {
    const t = useT();
    const author = message.author;
    const hasBody = message.body.trim() !== '';
    const { ack, copy } = useCopyAck();
    const rowReactions: RowReactions = {
        chips: reactions.chips,
        vocabulary: reactions.vocabulary,
        onToggle: reactions.canReact ? reactions.onToggle : undefined,
        onShowReactors: reactions.onShowReactors,
    };

    const ackLine =
        ack === null
            ? null
            : ack.what === 'link'
              ? ack.outcome === 'copied'
                  ? t('Link copied.')
                  : t('The link could not be copied.')
              : ack.outcome === 'copied'
                ? t('Text copied.')
                : t('The text could not be copied.');

    // canCopyLink puts the menu on rows whose reader has no other power — an Everyone room's
    // non-member — deliberately: an address is takeable by anyone who may read the message.
    const items: (RowMenuItem | null)[] = [
        reactorsItem(t, rowReactions),
        canReply ? { label: t('Reply'), icon: Reply, onSelect: onReply } : null,
        canCopyText(message.body) ? { label: t('Copy text'), icon: Copy, onSelect: () => copy('text', message.body) } : null,
        canCopyLink() ? { label: t('Copy link'), icon: LinkIcon, onSelect: () => copy('link', messageLink(message.id)) } : null,
        // The menu names no message, so the action must say what it acts on.
        message.canDelete ? { label: t('Delete message'), icon: Trash2, destructive: true, onSelect: () => onDelete(message.id) } : null,
    ];
    const menu = <RowMenu items={items} />;

    const body = (
        <RowBody reactions={rowReactions} trailing={grouped ? menu : undefined}>
            {/* Trimmed rather than compared to '': an upgraded body may be whitespace, and an empty
                paragraph would leave its height behind. */}
            {hasBody && (
                <p className={cn('whitespace-pre-wrap break-words', !grouped && 'mt-1')}>
                    <EntityText text={message.body} mentions={message.mentions} />
                </p>
            )}
            <LinkCard card={message.linkCard} className="mt-2" />
            <ImageGrid images={message.images} variant="boxed" className={hasBody ? 'mt-2' : grouped ? undefined : 'mt-1'} />
        </RowBody>
    );

    return (
        // The id is the scroll anchor "load older" holds while the page grows above it.
        <li
            data-talk-message-id={message.id}
            className={cn(
                // `isolate` keeps the highlight layer's negative depth inside the row: it is meant to
                // sit under the words and over whatever the row itself paints, not under the list.
                'group relative isolate px-4 sm:px-5',
                // The space between turns is a margin rather than padding, so the tint under the
                // pointer wraps the words evenly and the gap between two turns stays untinted.
                'py-1',
                !grouped && !separatorAbove && 'mt-3',
                // `hover:` is a hover-capable query, so a finger leaves no tint stuck behind it.
                'transition-colors duration-100 hover:bg-muted',
            )}
        >
            {/* 8% keeps the author link at AA over the hover tint; only the removal fades, since
                `duration-0` on the lit arm keeps the emphasis from easing in over the second the
                reader is looking for the row. */}
            <span
                aria-hidden
                className={cn(
                    'pointer-events-none absolute inset-0 -z-10 bg-selected/8 transition-opacity motion-reduce:transition-none',
                    highlighted ? 'opacity-100 duration-0' : 'opacity-0 duration-1000',
                )}
            />
            {/* Rows are named as well as columns: with only the column fixed, the face would be
                auto-placed into the first row with room for it, which is the reference's. */}
            <div className="grid grid-cols-[2.5rem_1fr] gap-x-2">
                {/* Above the author header, and why a reply never groups (lib/chat/message-grouping):
                    the reference needs the header under it to say who is answering. */}
                {message.inReplyTo !== null && (
                    // Across both columns, with a gutter of its own (ReplyHeader): the width is
                    // declared twice and the two have to agree.
                    <div className="col-span-2 col-start-1 row-start-1 min-w-0">
                        <ReplyHeader reference={message.inReplyTo} onJump={onJumpToReply} />
                    </div>
                )}
                <div className="col-start-1 row-start-2">
                    {grouped ? (
                        // `aria-hidden`, because a screen reader is told rather than shown: a folded
                        // row speaks its author and its time below.
                        <span
                            aria-hidden
                            className="block text-right text-xs leading-6 text-muted-foreground tabular-nums opacity-0 transition-opacity group-hover:opacity-100 group-has-[:focus-visible]:opacity-100 motion-reduce:transition-none"
                        >
                            <Timestamp at={message.createdAt} preset="clockTime" />
                        </span>
                    ) : (
                        <Avatar
                            id={author?.id ?? 0}
                            name={author?.name ?? ''}
                            src={author?.imageUrl ?? null}
                            color={author?.avatarColor ?? null}
                            isAi={author?.isAi ?? false}
                            size="md"
                            decorative
                        />
                    )}
                </div>
                <div className="col-start-2 row-start-2 min-w-0">
                    {grouped ? (
                        <>
                            <span className="sr-only">
                                {author?.name ?? t('Withdrawn member')}, <Timestamp at={message.createdAt} preset="clockTime" />
                            </span>
                            {body}
                        </>
                    ) : (
                        <>
                            <div className="flex items-center gap-2 text-sm text-muted-foreground">
                                {author ? (
                                    <Link href={`/member/${author.id}`} className="truncate text-link hover:underline">
                                        {author.name}
                                    </Link>
                                ) : (
                                    <span className="truncate">{t('Withdrawn member')}</span>
                                )}
                                <AiChip isAi={author?.isAi ?? false} />
                                <Timestamp at={message.createdAt} preset="clockTime" className="shrink-0" />
                                <span className="ml-auto shrink-0">{menu}</span>
                            </div>
                            {body}
                        </>
                    )}
                    {/* Beside the row's controls rather than inside a menu item: the item is gone once chosen. */}
                    {ackLine !== null && <p className={cn('mt-1 text-xs', ack?.outcome === 'copied' ? 'text-success' : 'text-destructive')}>{ackLine}</p>}
                    <span aria-live="polite" className="sr-only">
                        {ackLine}
                    </span>
                </div>
            </div>
        </li>
    );
}
