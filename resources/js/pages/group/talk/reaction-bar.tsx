import { SmilePlus, Users } from 'lucide-react';
import { useEffect, useRef } from 'react';
import type { ChatReactionChip } from '@/lib/chat/types';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * The emoji on one message, in two places: the chips under what was said, and the button that adds
 * one on the meta row above it. Split because they answer different questions — the chips are part
 * of the message, the way to react to it is one of the things you can do with it.
 *
 * A tap on a chip is that emoji's own toggle: holding it takes it back, not holding it adds it. Both
 * are drawn from the chip's `mine`, so what the control does is what the row already shows.
 */

const CHIP_BASE =
    'inline-flex min-h-8 items-center gap-1 rounded-full border px-2 text-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring';

const CHIP_MINE = 'border-selected bg-selected/10 text-foreground';

const CHIP_THEIRS = 'border-input text-muted-foreground';

const ICON_BUTTON =
    'inline-flex size-8 shrink-0 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring';

export function TalkReactionChips({
    chips,
    onToggle,
    onShowReactors,
}: {
    chips: ChatReactionChip[];
    /** Absent for a reader who may not post here: the chips stay, the way to change them does not. */
    onToggle?: (emoji: string, mine: boolean) => void;
    onShowReactors: () => void;
}) {
    const t = useT();

    if (chips.length === 0) {
        return null;
    }

    return (
        <div className="mt-2 flex flex-wrap items-center gap-1">
            {chips.map((chip) =>
                onToggle === undefined ? (
                    <span key={chip.emoji} className={cn(CHIP_BASE, chip.mine ? CHIP_MINE : CHIP_THEIRS)}>
                        <span>{chip.emoji}</span>
                        <span className="tabular-nums">{chip.count}</span>
                    </span>
                ) : (
                    <button
                        key={chip.emoji}
                        type="button"
                        aria-pressed={chip.mine}
                        onClick={() => onToggle(chip.emoji, chip.mine)}
                        className={cn(CHIP_BASE, chip.mine ? CHIP_MINE : `${CHIP_THEIRS} hover:bg-accent hover:text-accent-foreground`)}
                    >
                        <span>{chip.emoji}</span>
                        <span className="tabular-nums">{chip.count}</span>
                    </button>
                ),
            )}
            {/* Only ever offered beside chips: with none there is nobody to name. */}
            <button type="button" aria-label={t('See who reacted')} onClick={onShowReactors} className={ICON_BUTTON}>
                <Users className="size-4" aria-hidden />
            </button>
        </div>
    );
}

export function TalkReactionAdd({
    chips,
    vocabulary,
    open,
    onOpenChange,
    onPick,
}: {
    chips: ChatReactionChip[];
    /** What this site offers, as the page was rendered with it — never a copy held in the bundle. */
    vocabulary: string[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onPick: (emoji: string, mine: boolean) => void;
}) {
    const t = useT();
    const anchor = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open) {
            return;
        }

        const dismiss = (event: Event) => {
            // The trigger is inside the anchor, so its own press is not an outside one — it toggles
            // the picker shut on the click that follows instead of reopening what this just closed.
            if (!(event.target instanceof Node) || anchor.current?.contains(event.target) !== true) {
                onOpenChange(false);
            }
        };
        const escape = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                onOpenChange(false);
            }
        };

        document.addEventListener('mousedown', dismiss);
        document.addEventListener('touchstart', dismiss);
        document.addEventListener('keydown', escape);

        return () => {
            document.removeEventListener('mousedown', dismiss);
            document.removeEventListener('touchstart', dismiss);
            document.removeEventListener('keydown', escape);
        };
    }, [open, onOpenChange]);

    return (
        <div ref={anchor} className="relative shrink-0">
            <button
                type="button"
                aria-label={t('Add a reaction')}
                aria-expanded={open}
                aria-haspopup="true"
                onClick={() => onOpenChange(!open)}
                className={ICON_BUTTON}
            >
                <SmilePlus className="size-4" aria-hidden />
            </button>
            {open && (
                // Upward and right-aligned: the button sits at the end of a row inside a list that
                // usually ends at the foot of the screen, so below is where there is no room. A plain
                // absolute child rather than a portal — the message list carries no transform, and
                // the picker belongs to the row it opened from.
                //
                // Four columns' worth of width, wrapping past that. Sizing it to the vocabulary
                // instead would run off the left edge of a phone from an anchor already at the right
                // one, and a set this list does not choose could be any length.
                <div
                    className="absolute bottom-full right-0 z-20 mb-1 flex w-max max-w-[13.5rem] flex-wrap gap-1 rounded-xl border border-border bg-card p-2 shadow-lg"
                    aria-label={t('Reactions')}
                >
                    {vocabulary.map((emoji) => {
                        const mine = chips.some((chip) => chip.emoji === emoji && chip.mine);

                        return (
                            <button
                                key={emoji}
                                type="button"
                                aria-pressed={mine}
                                onClick={() => onPick(emoji, mine)}
                                className={cn(
                                    'inline-flex size-10 items-center justify-center rounded-full text-lg transition-colors hover:bg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                    mine && 'bg-selected/10 ring-1 ring-selected',
                                )}
                            >
                                {emoji}
                            </button>
                        );
                    })}
                </div>
            )}
        </div>
    );
}
