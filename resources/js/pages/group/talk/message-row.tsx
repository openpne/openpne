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
 * a keyboard reaching into it brings on — a row at rest is what was said, not what can be done about
 * it, and standing them in the flow would hold their width open on every row for controls nobody is
 * looking at.
 *
 * `:focus-visible` rather than `:focus-within` for that keyboard half. A click leaves focus on what
 * was clicked, so a reader who follows a link or opens a picture in the body takes the pointer away
 * and leaves the bar behind — revealed over a row nobody is on, until something else is clicked. The
 * browser withholds `:focus-visible` from a mouse click for exactly this reason, and Tab still brings
 * the bar out where it is the only way to reach it.
 *
 * Where there is no cursor they are `sr-only` rather than hidden: a long press opens the sheet, and a
 * screen reader on a touch screen cannot hold one, so these buttons are that reader's only way to
 * what the sheet offers.
 *
 * The `pointer-events` half is what keeps an invisible Delete from answering a finger on a hybrid
 * machine — a laptop with a touch screen answers `pointer: fine`, so the controls stay drawn there,
 * and `opacity: 0` alone does not stop a tap. The revealing states beat the default by selector
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
 * siblings for exactly as long as the bar is out (the `z-10` set on the row below matches these
 * revealing states — `data-ack` among them, so an acknowledgement outlives the pointer leaving). Without the lift the overhang is painted over by the next row — which
 * takes its hits as well, so a cursor moving down onto the bar's own foot leaves the row, and the
 * bar the hand was reaching for disappears. Nothing here may reintroduce a row-height floor: the
 * spacing between turns is the list's to choose. On the list's first row with no older history, the
 * card's own clip shaves 4px off the bar's top — the clip does not branch on the frame, so this holds
 * at every width — known and accepted over teaching the first row a different geometry.
 */
const ROW_ACTIONS =
    'absolute right-2 -top-1 z-10 flex items-center gap-1 rounded-lg border border-border bg-card px-1 py-0.5 text-sm text-muted-foreground shadow-sm opacity-0 transition-opacity motion-reduce:transition-none pointer-fine:pointer-events-none group-hover:opacity-100 group-hover:pointer-events-auto group-has-[:focus-visible]:opacity-100 group-has-[:focus-visible]:pointer-events-auto has-[[aria-expanded=true]]:opacity-100 has-[[aria-expanded=true]]:pointer-events-auto has-[[data-ack]]:opacity-100 has-[[data-ack]]:pointer-events-auto pointer-coarse:sr-only pointer-coarse:focus-within:not-sr-only pointer-coarse:focus-within:absolute';

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
/** How long the glyph answers for a completed copy before the button reads as an offer again. */
const COPIED_MS = 1500;

/**
 * The bar's copy-link control, answering its own click: the glyph becomes a check for a moment and a
 * spoken line says what happened — a clipboard write leaves nothing on screen, so without this the
 * click cannot be told from a miss. A refusal is answered too, and honestly: silence there would
 * leave the clipboard's *previous* contents to read as a copy that worked. The accessible name never
 * changes — the control is still the same control — so the acknowledgement is spoken beside it, as
 * the mute toggle speaks. `data-ack` also holds the bar out while an answer is showing
 * ({@see ROW_ACTIONS}): a click that leaves the row must not fade the check it earned.
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
            {/* Beside the control rather than inside it, where the mute toggle puts its line: a
                button's subtree is presentational by the ARIA spec, and Chromium declining to prune
                it is not a contract. In the tree whether or not it has words, so the change is what
                is announced. */}
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
    /** Stage this message as the one a new post answers. */
    onReply: () => void;
    /** Go to the message this one answers — scrolled to if on screen, fetched into view if not. */
    onJumpToReply: (parent: { id: number; cursor: string }) => void;
    /** Whether the viewer may post, and so start a reply. Not the message's own fact like canDelete. */
    canReply: boolean;
    highlighted?: boolean;
    grouped?: boolean;
    /** Whether a heading or the unread line stands above this row, and so already holds the boundary's
     *  space. Such a row opens a turn (it can never be `grouped`) but must not add its own on top.
     *  True on the list's first row, always — it opens a day — which is what keeps a margin off the
     *  first child and out of the margin-collapsing question entirely. */
    separatorAbove?: boolean;
    reactions: TalkRowReactions;
}) {
    const t = useT();
    const author = message.author;
    const hasBody = message.body.trim() !== '';
    // Whether a press has anything to open.
    const pressOpens =
        reactions.canReact || canReply || message.canDelete || canCopyText(message.body) || canCopyLink() || reactions.chips.length > 0;
    const press = useLongPress(onOpenActions, { enabled: pressOpens });

    const content = (
        <>
            {/* A message may be nothing but pictures, and an empty paragraph would leave its height
                behind. Trimmed rather than compared to '': an upgraded body may be whitespace. */}
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

    // canCopyLink puts the bar (and the sheet a long press opens) on rows whose reader has no
    // other power — an Everyone room's non-member. Deliberate: the address of a message is
    // takeable by anyone who may read it, and it is the first control that lane has had.
    const actions = (reactions.canReact || canReply || message.canDelete || canCopyLink()) && (
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
                // A glyph shaped like its neighbour, so the bar reads as one set of controls; the
                // name says what a glyph cannot, and what it is for turns red only under the hand —
                // on a touch screen this button is a screen reader's only delete, heard once per row.
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
                // Every row a finger can reach, and deliberately not gated on `pressOpens`: the lens
                // and the image menu a held finger raises would land on top of the sheet, and a
                // cursor's text selection is nobody's to take.
                //
                // What pays for the selection is the sheet's own copy item — which a deployment
                // without a clipboard cannot offer, so there the words become uncopyable. Withholding
                // the suppression there does not follow from that. The press would likely go with the
                // native selection that replaces it — expected, not measured on a device — and a
                // finger has no other way to this row's actions, the bar being sr-only for one. So it
                // risks trading copying for reacting, and wants a fallback rather than a condition
                // here. Saving a picture is unaffected either way: the lightbox a tap opens
                // suppresses neither.
                'pointer-coarse:select-none pointer-coarse:[-webkit-touch-callout:none]',
                // Turns are told apart by the space above them, not by a line: a rule between two
                // people speaking is the vocabulary of a board, and this is a conversation.
                //
                // The padding is even, and the space between turns is a margin. That split is what
                // the tint under the pointer answers to: it reaches as far as the padding and no
                // further, so it wraps the words evenly instead of trailing a tall empty band over
                // the row's head. The gap between two turns belongs to neither of them and stays
                // untinted. Within a run there is no margin at all, so a run's rows tint as one
                // continuous block.
                'py-1',
                !grouped && !separatorAbove && 'mt-3',
                // Above its siblings for as long as its controls are out, so a bar taller than its
                // own row keeps the hits it draws over the rows either side (see ROW_ACTIONS).
                'hover:z-10 has-[:focus-visible]:z-10 has-[[aria-expanded=true]]:z-10 has-[[data-ack]]:z-10',
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
                    {grouped ? (
                        // What the gutter is for on a row that folded its header away. A follow-up
                        // says nothing about when it was said, and the run it belongs to is only
                        // seven minutes wide (lib/chat/message-grouping) — so the answer is worth
                        // little enough not to earn a line on every row, and enough to be reachable.
                        // Under the cursor, as Slack and Discord both put it, in the space the face
                        // would have taken.
                        //
                        // `hover:` is a hover-capable query, so this is a cursor's affordance alone:
                        // a touch screen never reveals it, and there is nothing there to reveal.
                        // That is the same choice those two make, and for the same reason — the run's
                        // opening row carries a visible time a few lines up.
                        //
                        // `aria-hidden`, because a screen reader is told rather than shown: a folded
                        // row speaks its author and its time (below). `leading-6` matches the body's
                        // line so the stamp sits on the first line of what it dates, and
                        // `tabular-nums` keeps a run's times in a column — the same reason
                        // docs/internals/datetime.md pads the hour.
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
