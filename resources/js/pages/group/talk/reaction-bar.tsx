import { SmilePlus, Users } from 'lucide-react';
import { useState } from 'react';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Tip } from '@/components/ui/tooltip';
import type { ChatReactionChip } from '@/lib/chat/types';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * A tap on a chip is that emoji's own toggle, drawn from the chip's `mine`, so what the control does
 * is what the row already shows.
 */

const CHIP_BASE =
    'inline-flex min-h-8 items-center gap-1 rounded-full border px-2 text-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring';

const CHIP_MINE = 'border-selected bg-selected/10 text-foreground';

const CHIP_THEIRS = 'border-input text-muted-foreground';

export const ICON_BUTTON =
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
        // The attribute is the seam a verification script holds the chips by, the way the row's id names the row.
        <div data-talk-reactions className="mt-2 flex flex-wrap items-center gap-1">
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
            <Tip label={t('See who reacted')}>
                <button type="button" onClick={onShowReactors} className={ICON_BUTTON}>
                    <Users className="size-4" aria-hidden />
                </button>
            </Tip>
        </div>
    );
}

// The transparent border is the held state's canvas: mine recolours it the way a held chip does,
// so "this one is yours" is said the same way wherever an emoji can be pressed.
const PICKER_BUTTON =
    'inline-flex items-center justify-center rounded-full border border-transparent text-lg transition-colors hover:bg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring';

// Three is what fits before the bar crowds the row it floats over.
export const QUICK_REACTIONS = 3;

/**
 * One source for both places the picker is offered, so the two cannot drift into different answers to
 * the same question. The buttons come loose rather than in a box: what encloses them is the caller's
 * business.
 */
export function TalkReactionPickerGrid({
    chips,
    vocabulary,
    onPick,
    buttonClassName = 'size-10',
}: {
    chips: ChatReactionChip[];
    vocabulary: string[];
    onPick: (emoji: string, mine: boolean) => void;
    /** The tap target: a cursor's bar and popover work at 32–40px, a thumb's sheet wants past the 44 floor. */
    buttonClassName?: string;
}) {
    return (
        <>
            {vocabulary.map((emoji) => {
                const mine = chips.some((chip) => chip.emoji === emoji && chip.mine);

                return (
                    <button
                        key={emoji}
                        type="button"
                        aria-pressed={mine}
                        onClick={() => onPick(emoji, mine)}
                        className={cn(PICKER_BUTTON, buttonClassName, mine && 'border-selected bg-selected/10')}
                    >
                        {emoji}
                    </button>
                );
            })}
        </>
    );
}

export function TalkReactionAdd({
    chips,
    vocabulary,
    onPick,
}: {
    chips: ChatReactionChip[];
    /** What this site offers, as the page was rendered with it — never a copy held in the bundle. */
    vocabulary: string[];
    onPick: (emoji: string, mine: boolean) => void;
}) {
    const t = useT();
    // Each row's picker holds its own: pressing another row's button is an outside press to this one,
    // so one is open at a time without the page closing it from outside.
    const [open, setOpen] = useState(false);

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <Tip label={t('Add a reaction')}>
                <PopoverTrigger asChild>
                    <button type="button" className={ICON_BUTTON}>
                        <SmilePlus className="size-4" aria-hidden />
                    </button>
                </PopoverTrigger>
            </Tip>
            {/* Portalled because the card the list stands in clips its overflow, and capped at four
                columns so a set this list does not choose cannot run off a phone's edge. */}
            <PopoverContent side="top" align="end" aria-label={t('Reactions')} className="flex w-max max-w-[13.5rem] flex-wrap gap-1">
                <TalkReactionPickerGrid
                    chips={chips}
                    vocabulary={vocabulary}
                    onPick={(emoji, mine) => {
                        setOpen(false);
                        onPick(emoji, mine);
                    }}
                />
            </PopoverContent>
        </Popover>
    );
}
