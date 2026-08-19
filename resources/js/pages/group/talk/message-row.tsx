import { Link } from '@inertiajs/react';
import { Reply, Trash2 } from 'lucide-react';
import { AiChip } from '@/components/ai-chip';
import { Avatar } from '@/components/avatar';
import { Timestamp } from '@/components/timestamp';
import { EntityText } from '@/components/entity-text';
import { ImageGrid } from '@/components/image-grid';
import type { ChatReactionChip } from '@/lib/chat/types';
import { useT } from '@/lib/i18n';
import { useLongPress } from '@/lib/use-long-press';
import { cn } from '@/lib/utils';
import { canCopyText } from './message-sheet';
import { ICON_BUTTON, QUICK_REACTIONS, TalkReactionAdd, TalkReactionChips, TalkReactionPickerGrid } from './reaction-bar';
import type { TalkMessage, TalkReplyReference } from './types';

/**
 * The reference a reply draws above its own header: who it answers and a glimpse of what they said,
 * muted so it reads as context rather than the message. The excerpt is bounded server-side (the
 * server's ChatPreview); the visible cut is this line's `truncate`.
 *
 * The live variant is one button over the whole line — the jump target is the reference, not a word
 * of it. A parent that was deleted between render and click keeps the same line, italic and inert:
 * there is nowhere to jump to.
 *
 * No glyph for "reply": the elbow drawn in the gutter beside it already says the line answers
 * something, and a second mark saying it in the same breath only takes room the excerpt wants.
 *
 * The button carries no `aria-label`: one would override the name computed from the contents and
 * leave every reply header reading as the same "go to" button, dropping exactly the who-and-what the
 * reference exists to say. So the action is an sr-only prefix instead, and the name is built from the
 * prefix, the author and the excerpt — the picture stays alt-less, a glimpse the excerpt already
 * names.
 */
function ReplyHeader({ reference, onJump }: { reference: TalkReplyReference; onJump: (parent: { id: number; cursor: string }) => void }) {
    const t = useT();

    /**
     * The elbow: down the gutter and into the top of the face below. Standing in the words' column
     * with nothing else to say so, the reference reads as the tail of the message above rather than
     * the head of its own — it is the only thing on the row whose neighbour above is a stranger's
     * words. It is not the hairline this list gave up: that one separated two rows, this one joins
     * two parts of the same one, which is why it is drawn thicker than a rule and not across.
     *
     * `top-1/2` puts the arm at the middle of one line, and the reference is one line by
     * construction — the excerpt truncates rather than wraps. Let it wrap and the arm lands at the
     * middle of the block instead, floating below the line it points at.
     *
     * `-bottom-1` spends the margin under the reference so the stroke arrives at the face rather
     * than stopping four pixels short of it.
     *
     * Drawn from the muted text colour rather than the border token, and two pixels rather than one:
     * the token is calibrated for a hairline nobody is meant to look at, and this is a stroke that
     * has to be followed. It darkens to the full colour under the cursor — with the words beside it,
     * since the whole line is one button.
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
        // The gutter is inside the button, so the elbow answers to the same hover the words do and
        // the line beside a reference is part of what opens it — as it is in every client that
        // draws one.
        //
        // `cursor-pointer` because this button is a line of muted text: nothing about it looks
        // pressable, and the app's chrome'd buttons keep the arrow they are born with (this one is
        // the exception that says so, not a new rule for buttons).
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
 * The row's half of the reactions: what to draw, and what a tap means. The chips are the message's
 * own as the stream holds them, with whatever tap is still on the wire drawn over them — the page
 * owns both, since a tap outlives the row it was made on.
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
 * The row's controls, and the two lanes they are reached by.
 *
 * Where a cursor can point they float over the row's top-right on reveal, which hovering the row or
 * focus reaching into it brings on — a row at rest is what was said, not what can be done about it,
 * and standing them in the flow would hold their width open on every row for controls nobody is
 * looking at. Where there is no cursor they are `sr-only` rather than hidden: a long press opens the
 * sheet, and a screen reader on a touch screen cannot hold one, so these buttons are that reader's
 * only way to what the sheet offers.
 *
 * The `pointer-events` half is what keeps an invisible Delete from answering a finger on a hybrid
 * machine — a laptop with a touch screen answers `pointer: fine`, so the controls stay drawn there,
 * and `opacity: 0` alone does not stop a tap. The three revealing states beat the default by selector
 * specificity (0,2,0 against 0,1,0), not by source order: Tailwind emits `pointer-fine` after them.
 * Simplifying the reveal to a bare `pointer-events-auto` would tie the specificity, hand the cascade
 * back to source order, and leave the controls dead to every click. Nothing in the coarse lane writes
 * `pointer-events` at all, so a touch screen reader's activation path is untouched.
 *
 * One class wins differently: the trailing `pointer-coarse:focus-within:absolute` re-floats the bar
 * when a hardware keyboard tabs into the coarse lane — `not-sr-only`'s `position: static` would
 * otherwise drop it into the flow and shove the row taller on every Tab. It ties that rule's
 * specificity and wins on emission order alone, so it is the one order-dependent piece here: if it
 * ever loses, the bar goes back to standing in the flow, wider — a look, not a lockout.
 *
 * **The bar is taller than the row it belongs to**, and is meant to be: 38px of controls over a
 * follow-up row that is one line of text. It overhangs both edges, so the row lifts above its
 * siblings for exactly as long as the bar is out (the `z-10` trio on the row below matches these
 * three revealing states). Without the lift the overhang is painted over by the next row — which
 * takes its hits as well, so a cursor moving down onto the bar's own foot leaves the row, and the
 * bar the hand was reaching for disappears. Nothing here may reintroduce a row-height floor: the
 * spacing between turns is the list's to choose. On the list's first row with no older history, the
 * card's own clip shaves 4px off the bar's top — known and accepted over teaching the first row a
 * different geometry.
 */
const ROW_ACTIONS =
    'absolute right-2 -top-1 z-10 flex items-center gap-1 rounded-lg border border-border bg-card px-1 py-0.5 text-sm text-muted-foreground shadow-sm opacity-0 transition-opacity motion-reduce:transition-none pointer-fine:pointer-events-none group-hover:opacity-100 group-hover:pointer-events-auto group-focus-within:opacity-100 group-focus-within:pointer-events-auto has-[[aria-expanded=true]]:opacity-100 has-[[aria-expanded=true]]:pointer-events-auto pointer-coarse:sr-only pointer-coarse:focus-within:not-sr-only pointer-coarse:focus-within:absolute';

/**
 * One utterance. Shaped like a board comment rather than a two-sided bubble stream: the same row a
 * reader already knows from topics and events, so a conversation and a thread are read the same way.
 * A withdrawn author keeps their place with the established label — the message stays, the person is
 * gone.
 *
 * `grouped` drops the author header: a quick follow-up in the same run (lib/chat/message-grouping)
 * reads as more of the same turn. The attribution stays, spoken rather than drawn, so a screen reader
 * hears every row whole. Its gutter is left empty rather than closed up, which is what keeps a run's
 * words under the name that opened it.
 *
 * `onOpenActions` is the touch lane's way in — the whole row, gutters included, is the press target
 * (see ROW_ACTIONS for the other one). It is offered only where there is something to offer: a row
 * with no reaction to make, no reply to start, nothing to delete, no body to copy and no chips to
 * look behind has an empty sheet, so it gets no press at all and keeps the browser's own long-press
 * behaviour.
 *
 * `highlighted` is the deep link's landing: the row a `?m=` link opened on — and now also the parent
 * a reply header jumped to — held for a moment so the reader can see which message named them.
 */
export function TalkMessageRow({
    message,
    onDelete,
    onOpenActions,
    onReply,
    onJumpToReply,
    canReply,
    highlighted = false,
    grouped = false,
    reactions,
}: {
    message: TalkMessage;
    onDelete: (id: number) => void;
    onOpenActions: () => void;
    /** Stage this message as the one a new post answers. */
    onReply: () => void;
    /** Go to the message this one answers — scrolled to if on screen, fetched into view if not. */
    onJumpToReply: (parent: { id: number; cursor: string }) => void;
    /** Whether the viewer may post, and so start a reply. Not the message's own fact like canDelete. */
    canReply: boolean;
    highlighted?: boolean;
    grouped?: boolean;
    reactions: TalkRowReactions;
}) {
    const t = useT();
    const author = message.author;
    const hasBody = message.body.trim() !== '';
    const press = useLongPress(onOpenActions, {
        enabled: reactions.canReact || canReply || message.canDelete || canCopyText(message.body) || reactions.chips.length > 0,
    });

    const content = (
        <>
            {/* A message may be nothing but pictures, and an empty paragraph would leave its height
                behind. Trimmed rather than compared to '': an upgraded body may be whitespace. */}
            {hasBody && (
                <p className={cn('whitespace-pre-wrap break-words', !grouped && 'mt-1')}>
                    <EntityText text={message.body} mentions={message.mentions} />
                </p>
            )}
            <ImageGrid images={message.images} variant="boxed" className={hasBody ? 'mt-2' : grouped ? undefined : 'mt-1'} />
            <TalkReactionChips
                chips={reactions.chips}
                onToggle={reactions.canReact ? reactions.onToggle : undefined}
                onShowReactors={reactions.onShowReactors}
            />
        </>
    );

    const actions = (reactions.canReact || canReply || message.canDelete) && (
        <div className={ROW_ACTIONS}>
            {reactions.canReact && (
                <>
                    {/* The head of the vocabulary stands in the bar itself, drawn by the picker's own
                        component: a reaction is then one click rather than a click into a popover, and
                        an emoji says "yours" the same way whichever of the two places it was pressed
                        in. The picker still offers the whole set, these three included — taking them
                        out of it would move an emoji's place depending on what is already held.

                        Gone entirely on coarse pointers, not just invisible: there they add nothing
                        the sheet does not offer, while a screen reader would hear each one beside the
                        same emoji's chip — two same-named toggles per row with no way to tell which
                        is which. */}
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
                <button type="button" aria-label={t('Reply')} onClick={onReply} className={ICON_BUTTON}>
                    <Reply className="size-4" aria-hidden />
                </button>
            )}
            {message.canDelete && (
                // A glyph shaped like its neighbour, so the bar reads as one set of controls; the
                // name says what a glyph cannot, and what it is for turns red only under the hand —
                // on a touch screen this button is a screen reader's only delete, heard once per row.
                <button
                    type="button"
                    aria-label={t('Delete message')}
                    onClick={() => onDelete(message.id)}
                    className={cn(ICON_BUTTON, 'hover:bg-destructive/10 hover:text-destructive')}
                >
                    <Trash2 className="size-4" aria-hidden />
                </button>
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
                // Only where the press is the way in: the lens and the image menu a held finger raises
                // would land on top of the sheet, and a cursor's text selection is nobody's to take.
                // Saving a picture still has a way: the lightbox a tap opens suppresses neither.
                'pointer-coarse:select-none pointer-coarse:[-webkit-touch-callout:none]',
                // Turns are told apart by the space above them, not by a line: a rule between two
                // people speaking is the vocabulary of a board, and this is a conversation. Within a
                // run the rows close up to a hair, since what separates them is only a pause. No gap
                // between rows anywhere — the hover tint reaches as far as the padding does, and a
                // gap would draw it as stripes.
                grouped ? 'py-0.5' : 'pt-4 pb-0.5',
                // Above its siblings for as long as its controls are out, so a bar taller than its
                // own row keeps the hits it draws over the rows either side (see ROW_ACTIONS).
                'hover:z-10 focus-within:z-10 has-[[aria-expanded=true]]:z-10',
                // Under the pointer the row says which one it is, quickly enough to track the hand.
                // `hover:` is a hover-capable query, so a finger leaves no tint stuck behind it.
                'transition-colors duration-100 hover:bg-muted',
            )}
        >
            {/* The deep link's landing, as a layer rather than the row's own background: the row now
                tints under the pointer, and a highlight sharing that background would drag the
                pointer's tint into a second-long fade of its own.

                Only the removal fades: the emphasis itself is instant, whichever way it arrives — a
                deep link's landing mounts already highlighted, and an on-screen jump flips the flag
                on a row already painted, which `duration-0` on the lit arm keeps from fading in over
                the second the reader is looking for the row. The unlit arm carries the fade, so
                taking the highlight away still eases out.

                8%: enough tint to pick the row out, light enough that the author link holds AA even
                on the worst composite — this tint over the hover tint, where 10% read 4.39:1 and 8%
                reads 4.52:1. The link is measured against both layers, not the card alone. */}
            <span
                aria-hidden
                className={cn(
                    'pointer-events-none absolute inset-0 -z-10 bg-selected/8 transition-opacity motion-reduce:transition-none',
                    highlighted ? 'opacity-100 duration-0' : 'opacity-0 duration-1000',
                )}
            />
            {/* The face steps out of the header and into a gutter of its own, so everything the
                message says — its reference, its words, its pictures — starts where the name starts.
                Under the old shape the words began 48px to the *left* of the name that owned them,
                which left a follow-up row with nothing to say whose turn it was part of.

                A grid rather than two boxes side by side, because the reference is a third block and
                it shares the words' column: written into the flow above them, it would push the face
                down with it, and then a face would sit at a different height on a reply than on
                everything else. Rows are named as well as columns — with only the column fixed, the
                face would be placed in the first row that had room for it, which is the reference's.
                A row nothing is placed in has no height, so the ordinary message loses nothing to the
                one that is missing.

                The reference spans both columns and carries a gutter of its own (ReplyHeader), so the
                width is declared twice and the two have to agree. Widening one alone moves the
                reference off the words by the difference — silently, since the name and the body
                share a column and stay put. tools/ux-review/talk-row-shape-drive.cjs measures both
                left edges against each other for that reason. */}
            <div className="grid grid-cols-[2.5rem_1fr] gap-x-2">
                {/* Above the author header, and why a reply never groups (lib/chat/message-grouping):
                    the reference needs the header under it to say who is answering. */}
                {message.inReplyTo !== null && (
                    // Across both columns: the reference carries its own gutter, because the elbow
                    // drawn there is part of what a reader presses (see ReplyHeader).
                    <div className="col-span-2 col-start-1 row-start-1 min-w-0">
                        <ReplyHeader reference={message.inReplyTo} onJump={onJumpToReply} />
                    </div>
                )}
                <div className="col-start-1 row-start-2">
                    {!grouped && (
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
                                {/* Beside the name, not pushed to the far edge: who spoke and when they
                                    spoke are read together, and a column at the right put 435px of
                                    empty row between them on a desktop. */}
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
