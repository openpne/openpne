import { Link } from '@inertiajs/react';
import { AiChip } from '@/components/ai-chip';
import { Avatar } from '@/components/avatar';
import { Timestamp } from '@/components/timestamp';
import { dangerActionClass } from '@/components/ui/danger-link';
import { EntityText } from '@/components/entity-text';
import { ImageGrid } from '@/components/image-grid';
import type { ChatReactionChip } from '@/lib/chat/types';
import { useT } from '@/lib/i18n';
import { useLongPress } from '@/lib/use-long-press';
import { cn } from '@/lib/utils';
import { canCopyText } from './message-sheet';
import { TalkReactionAdd, TalkReactionChips } from './reaction-bar';
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
 * Where a cursor can point they are drawn on the row and revealed by hovering it or by focus reaching
 * into it — a row at rest is what was said, not what can be done about it. Where there is no cursor
 * they are `sr-only` rather than hidden: a long press opens the sheet, and a screen reader on a touch
 * screen cannot hold one, so these buttons are that reader's only way to what the sheet offers.
 *
 * The `pointer-events` half is what keeps an invisible Delete from answering a finger on a hybrid
 * machine — a laptop with a touch screen answers `pointer: fine`, so the controls stay drawn there,
 * and `opacity: 0` alone does not stop a tap. The three revealing states beat the default by selector
 * specificity (0,2,0 against 0,1,0), not by source order: Tailwind emits `pointer-fine` after them.
 * Simplifying the reveal to a bare `pointer-events-auto` would tie the specificity, hand the cascade
 * back to source order, and leave the controls dead to every click. Nothing in the coarse lane writes
 * `pointer-events` at all, so a touch screen reader's activation path is untouched.
 */
const ROW_ACTIONS =
    'flex shrink-0 items-center gap-2 text-sm text-muted-foreground opacity-0 transition-opacity motion-reduce:transition-none pointer-fine:pointer-events-none group-hover:opacity-100 group-hover:pointer-events-auto group-focus-within:opacity-100 group-focus-within:pointer-events-auto has-[[aria-expanded=true]]:opacity-100 has-[[aria-expanded=true]]:pointer-events-auto pointer-coarse:sr-only pointer-coarse:focus-within:not-sr-only';

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
            {reactions.canReact && <TalkReactionAdd chips={reactions.chips} vocabulary={reactions.vocabulary} onPick={reactions.onToggle} />}
            {message.canDelete && (
                <button type="button" onClick={() => onDelete(message.id)} className={`${dangerActionClass} shrink-0`}>
                    {t('Delete')}
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
                'group px-4 sm:px-5',
                // Only where the press is the way in: the lens and the image menu a held finger raises
                // would land on top of the sheet, and a cursor's text selection is nobody's to take.
                // Saving a picture still has a way: the lightbox a tap opens suppresses neither.
                'pointer-coarse:select-none pointer-coarse:[-webkit-touch-callout:none]',
                grouped ? 'pb-3' : 'py-3',
                rule && 'border-t border-border',
                // The transition is not conditional on the flag: what fades is the highlight being
                // taken away, and a transition arriving with the class would have nothing to animate
                // from. The row mounts already highlighted, so the emphasis itself is instant.
                'transition-colors duration-1000 motion-reduce:transition-none',
                // 10%: enough tint to pick the row out, light enough to leave the author link's own
                // contrast over AA (it is 4.48:1 at 15%).
                highlighted && 'bg-selected/10',
            )}
        >
            {grouped ? (
                <div className="flex items-start gap-2">
                    <div className="min-w-0 flex-1">
                        <span className="sr-only">
                            {author?.name ?? t('Withdrawn member')}, <Timestamp at={message.createdAt} preset="relative" />
                        </span>
                        {content}
                    </div>
                    {actions}
                </div>
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
                        {actions}
                    </div>
                    {content}
                </>
            )}
        </li>
    );
}
