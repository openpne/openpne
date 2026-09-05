import { Link } from '@inertiajs/react';
import { Check, Link as LinkIcon, Reply, Trash2, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { AiChip } from '@/components/ai-chip';
import { Avatar } from '@/components/avatar';
import { Timestamp } from '@/components/timestamp';
import { EntityText } from '@/components/entity-text';
import { ImageGrid } from '@/components/image-grid';
import { LinkCard } from '@/components/link-card';
import { Tip } from '@/components/ui/tooltip';
import type { ChatReactionChip } from '@/lib/chat/types';
import { useT } from '@/lib/i18n';
import { useLongPress } from '@/lib/use-long-press';
import { cn } from '@/lib/utils';
import { canCopyLink, canCopyText, messageLink } from './message-sheet';
import { ICON_BUTTON, QUICK_REACTIONS, TalkReactionAdd, TalkReactionChips, TalkReactionPickerGrid } from './reaction-bar';
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

/**
 * The revealing states beat `pointer-fine:pointer-events-none` by selector specificity, so a bare
 * `pointer-events-auto` would tie it and leave the controls dead to every click
 * (docs/internals/group-talk.md, "The row's action bar").
 */
const ROW_ACTIONS =
    'absolute right-2 -top-1 z-10 flex items-center gap-1 rounded-lg border border-border bg-card px-1 py-0.5 text-sm text-muted-foreground shadow-sm opacity-0 transition-opacity motion-reduce:transition-none pointer-fine:pointer-events-none group-hover:opacity-100 group-hover:pointer-events-auto group-has-[:focus-visible]:opacity-100 group-has-[:focus-visible]:pointer-events-auto has-[[aria-expanded=true]]:opacity-100 has-[[aria-expanded=true]]:pointer-events-auto has-[[data-ack]]:opacity-100 has-[[data-ack]]:pointer-events-auto pointer-coarse:sr-only pointer-coarse:focus-within:not-sr-only pointer-coarse:focus-within:absolute';

const COPIED_MS = 1500;

/**
 * A refusal is answered as well as a success: silence would leave the clipboard's previous contents
 * to read as a copy that worked. `data-ack` also holds the bar out while an answer is showing
 * ({@see ROW_ACTIONS}), so a click that leaves the row does not fade the check it earned.
 */
function CopyLinkButton({ messageId }: { messageId: number }) {
    const t = useT();
    const [ack, setAck] = useState<'copied' | 'failed' | null>(null);
    const timer = useRef<number | null>(null);
    const mounted = useRef(true);

    // An acknowledgement still pending when the row leaves must not set state on an unmounted
    // control — the write itself can outlive the row, so the flag covers the settle as well as the
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

    const answer = (outcome: 'copied' | 'failed') => {
        if (!mounted.current) {
            return;
        }
        setAck(outcome);
        if (timer.current !== null) {
            window.clearTimeout(timer.current);
        }
        timer.current = window.setTimeout(() => {
            setAck(null);
            timer.current = null;
        }, COPIED_MS);
    };

    return (
        <>
            <Tip label={t('Copy link')}>
                <button
                    type="button"
                    data-ack={ack ?? undefined}
                    onClick={() =>
                        void navigator.clipboard.writeText(messageLink(messageId)).then(
                            () => answer('copied'),
                            () => answer('failed'),
                        )
                    }
                    className={ICON_BUTTON}
                >
                    {ack === 'copied' ? (
                        <Check className="size-4 text-success" aria-hidden />
                    ) : ack === 'failed' ? (
                        <X className="size-4 text-destructive" aria-hidden />
                    ) : (
                        <LinkIcon className="size-4" aria-hidden />
                    )}
                </button>
            </Tip>
            {/* Beside the control rather than inside it: a button's subtree is presentational by the
                ARIA spec, and the region stays in the tree whether or not it has words. */}
            <span aria-live="polite" className="sr-only">
                {ack === 'copied' ? t('Link copied.') : ack === 'failed' ? t('The link could not be copied.') : null}
            </span>
        </>
    );
}

export function TalkMessageRow({
    message,
    onDelete,
    onOpenActions,
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
    onOpenActions: () => void;
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
    const pressOpens =
        reactions.canReact || canReply || message.canDelete || canCopyText(message.body) || canCopyLink() || reactions.chips.length > 0;
    const press = useLongPress(onOpenActions, { enabled: pressOpens });

    const content = (
        <>
            {/* Trimmed rather than compared to '': an upgraded body may be whitespace, and an empty
                paragraph would leave its height behind. */}
            {hasBody && (
                <p className={cn('whitespace-pre-wrap break-words', !grouped && 'mt-1')}>
                    <EntityText text={message.body} mentions={message.mentions} />
                </p>
            )}
            <LinkCard card={message.linkCard} className="mt-2" />
            <ImageGrid images={message.images} variant="boxed" className={hasBody ? 'mt-2' : grouped ? undefined : 'mt-1'} />
            <TalkReactionChips
                chips={reactions.chips}
                onToggle={reactions.canReact ? reactions.onToggle : undefined}
                onShowReactors={reactions.onShowReactors}
            />
        </>
    );

    // canCopyLink puts the bar on rows whose reader has no other power — an Everyone room's
    // non-member — deliberately: an address is takeable by anyone who may read the message.
    const actions = (reactions.canReact || canReply || message.canDelete || canCopyLink()) && (
        <div className={ROW_ACTIONS}>
            {reactions.canReact && (
                <>
                    {/* Gone entirely on coarse pointers rather than invisible: a screen reader would
                        otherwise hear each one beside the same emoji's chip, two same-named toggles
                        per row. */}
                    <div className="flex items-center gap-1 pointer-coarse:hidden">
                        <TalkReactionPickerGrid
                            chips={reactions.chips}
                            vocabulary={reactions.vocabulary.slice(0, QUICK_REACTIONS)}
                            onPick={reactions.onToggle}
                            buttonClassName="size-8 text-base"
                        />
                    </div>
                    <TalkReactionAdd chips={reactions.chips} vocabulary={reactions.vocabulary} onPick={reactions.onToggle} />
                </>
            )}
            {canReply && (
                <Tip label={t('Reply')}>
                    <button type="button" onClick={onReply} className={ICON_BUTTON}>
                        <Reply className="size-4" aria-hidden />
                    </button>
                </Tip>
            )}
            {canCopyLink() && (
                // The address the sheet offers a thumb, one click here: text is the cursor's to
                // select, so of the two copies only the link earns a place in the bar.
                <CopyLinkButton messageId={message.id} />
            )}
            {message.canDelete && (
                // On a touch screen this button is a screen reader's only delete, heard once per row.
                <Tip label={t('Delete message')}>
                    <button
                        type="button"
                        onClick={() => onDelete(message.id)}
                        className={cn(ICON_BUTTON, 'hover:bg-destructive/10 hover:text-destructive')}
                    >
                        <Trash2 className="size-4" aria-hidden />
                    </button>
                </Tip>
            )}
        </div>
    );

    return (
        // The id is the scroll anchor "load older" holds while the page grows above it.
        <li
            data-talk-message-id={message.id}
            {...press}
            className={cn(
                // `isolate` keeps the highlight layer's negative depth inside the row: it is meant to
                // sit under the words and over whatever the row itself paints, not under the list.
                'group relative isolate px-4 sm:px-5',
                // Deliberately not gated on `pressOpens`, since the lens and the image menu a held
                // finger raises would land on the sheet; on a no-clipboard install a touch reader
                // can then neither select nor copy a body, which is accepted.
                'pointer-coarse:select-none pointer-coarse:[-webkit-touch-callout:none]',
                // The space between turns is a margin rather than padding, so the tint under the
                // pointer wraps the words evenly and the gap between two turns stays untinted.
                'py-1',
                !grouped && !separatorAbove && 'mt-3',
                // Above its siblings for as long as its controls are out, so a bar taller than its
                // own row keeps the hits it draws over the rows either side (see ROW_ACTIONS).
                'hover:z-10 has-[:focus-visible]:z-10 has-[[aria-expanded=true]]:z-10 has-[[data-ack]]:z-10',
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
                            {content}
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
                            </div>
                            {content}
                        </>
                    )}
                </div>
            </div>
            {actions}
        </li>
    );
}
