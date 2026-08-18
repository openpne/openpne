import { Link } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
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
import type { TalkMessage } from './types';

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
 * `-top-1` leans the bar out the row's top rather than its foot: `isolate` keeps its z within the
 * row, rows behind paint first and rows after paint over — so what the bar overhangs must be the
 * row already painted, or a one-line follow-up's next sibling draws its hairline through it.
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
 * hears every row whole.
 *
 * `onOpenActions` is the touch lane's way in — the whole row, gutters included, is the press target
 * (see ROW_ACTIONS for the other one). It is offered only where there is something to offer: a row
 * with no reaction to make, nothing to delete, no body to copy and no chips to look behind has an
 * empty sheet, so it gets no press at all and keeps the browser's own long-press behaviour.
 *
 * `highlighted` is the deep link's landing: the row a `?m=` link opened on, held for a moment so the
 * reader can see which message named them.
 */
export function TalkMessageRow({
    message,
    onDelete,
    onOpenActions,
    highlighted = false,
    grouped = false,
    rule = false,
    reactions,
}: {
    message: TalkMessage;
    onDelete: (id: number) => void;
    onOpenActions: () => void;
    highlighted?: boolean;
    grouped?: boolean;
    /** Draw the hairline above this row — the list rules between turns, not inside them. */
    rule?: boolean;
    reactions: TalkRowReactions;
}) {
    const t = useT();
    const author = message.author;
    const hasBody = message.body.trim() !== '';
    const press = useLongPress(onOpenActions, {
        enabled: reactions.canReact || message.canDelete || canCopyText(message.body) || reactions.chips.length > 0,
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

    const actions = (reactions.canReact || message.canDelete) && (
        <div className={ROW_ACTIONS}>
            {reactions.canReact && (
                <>
                    {/* The head of the vocabulary stands in the bar itself, drawn by the picker's own
                        component: a reaction is then one click rather than a click into a popover, and
                        an emoji says "yours" the same way whichever of the two places it was pressed
                        in. The picker still offers the whole set, these three included — taking them
                        out of it would move an emoji's place depending on what is already held. */}
                    <TalkReactionPickerGrid
                        chips={reactions.chips}
                        vocabulary={reactions.vocabulary.slice(0, QUICK_REACTIONS)}
                        onPick={reactions.onToggle}
                        buttonClassName="size-8 text-base"
                    />
                    <TalkReactionAdd chips={reactions.chips} vocabulary={reactions.vocabulary} onPick={reactions.onToggle} />
                </>
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
                grouped ? 'pb-3' : 'py-3',
                rule && 'border-t border-border',
                // Under the pointer the row says which one it is, quickly enough to track the hand.
                // `hover:` is a hover-capable query, so a finger leaves no tint stuck behind it.
                'transition-colors duration-100 hover:bg-muted',
            )}
        >
            {/* The deep link's landing, as a layer rather than the row's own background: the row now
                tints under the pointer, and a highlight sharing that background would drag the
                pointer's tint into a second-long fade of its own.

                The transition is not conditional on the flag: what fades is the highlight being taken
                away, and a transition arriving with the class would have nothing to animate from. The
                row mounts already highlighted, so the emphasis itself is instant.

                10%: enough tint to pick the row out, light enough to leave the author link's own
                contrast over AA (it is 4.48:1 at 15%). */}
            <span
                aria-hidden
                className={cn(
                    'pointer-events-none absolute inset-0 -z-10 bg-selected/10 transition-opacity duration-1000 motion-reduce:transition-none',
                    highlighted ? 'opacity-100' : 'opacity-0',
                )}
            />
            {grouped ? (
                <>
                    <span className="sr-only">
                        {author?.name ?? t('Withdrawn member')}, <Timestamp at={message.createdAt} preset="relative" />
                    </span>
                    {content}
                </>
            ) : (
                <>
                    <div className="flex items-center gap-2 text-sm text-muted-foreground">
                        <Avatar
                            id={author?.id ?? 0}
                            name={author?.name ?? ''}
                            src={author?.imageUrl ?? null}
                            color={author?.avatarColor ?? null}
                            isAi={author?.isAi ?? false}
                            size="md"
                            decorative
                        />
                        {author ? (
                            <Link href={`/member/${author.id}`} className="truncate text-link hover:underline">
                                {author.name}
                            </Link>
                        ) : (
                            <span className="truncate">{t('Withdrawn member')}</span>
                        )}
                        <AiChip isAi={author?.isAi ?? false} />
                        <Timestamp at={message.createdAt} preset="relative" className="ml-auto shrink-0" />
                    </div>
                    {content}
                </>
            )}
            {actions}
        </li>
    );
}
