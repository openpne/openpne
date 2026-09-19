import { SmilePlus, Users } from 'lucide-react';
import { useRef, useState } from 'react';
import { ActionSheet } from '@/components/row/action-sheet';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Tip } from '@/components/ui/tooltip';
import type { ReactionChip } from '@/lib/reactions/types';
import { useT } from '@/lib/i18n';
import { useCoarsePointer } from '@/lib/use-coarse-pointer';
import { cn } from '@/lib/utils';

/**
 * A tap on a chip is that emoji's own toggle, drawn from the chip's `mine`, so what the control does
 * is what the row already shows.
 */
const CHIP_BASE =
    'inline-flex min-h-8 items-center gap-1 rounded-full border px-2 text-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring';

const CHIP_MINE = 'border-selected bg-selected/10 text-foreground';

const CHIP_THEIRS = 'border-input text-muted-foreground';

export interface RowReactions {
    chips: ReactionChip[];
    vocabulary: string[];
    /** Both absent for a reader who may not react here: the chips stay as counts. */
    onToggle?: (emoji: string, mine: boolean) => void;
    onShowReactors?: () => void;
}

export const ICON_BUTTON =
    'inline-flex size-8 shrink-0 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring';

/** A row's standing control: 32px drawn, and under a finger a 44px hit box that costs the row no height. */
export const ROW_ICON_BUTTON = cn(ICON_BUTTON, "relative pointer-coarse:before:absolute pointer-coarse:before:-inset-1.5 pointer-coarse:before:content-['']");

/** The chips alone, drawn only once there are any; whatever stands beside them is the caller's. */
export function ReactionChipsRow({
    chips,
    onToggle,
    children,
}: {
    chips: ReactionChip[];
    /** Absent for a reader who may not post here: the chips stay, the way to change them does not. */
    onToggle?: (emoji: string, mine: boolean) => void;
    children?: React.ReactNode;
}) {
    if (chips.length === 0) {
        return null;
    }

    return (
        // The attribute is the seam a verification script holds the chips by, the way the row's id names the row.
        <div data-reactions className="mt-2 flex flex-wrap items-center gap-1">
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
            {children}
        </div>
    );
}

/** A chat row's chips, the reactor list beside them. */
export function ReactionChips({
    chips,
    onToggle,
    onShowReactors,
}: {
    chips: ReactionChip[];
    onToggle?: (emoji: string, mine: boolean) => void;
    /** Absent for a reader the names are not offered to. */
    onShowReactors?: () => void;
}) {
    const t = useT();

    return (
        <ReactionChipsRow chips={chips} onToggle={onToggle}>
            {onShowReactors !== undefined && (
                <Tip label={t('See who reacted')}>
                    <button type="button" onClick={onShowReactors} className={ICON_BUTTON}>
                        <Users className="size-4" aria-hidden />
                    </button>
                </Tip>
            )}
        </ReactionChipsRow>
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
export function ReactionPickerGrid({
    chips,
    vocabulary,
    onPick,
    buttonClassName = 'size-10',
}: {
    chips: ReactionChip[];
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

export function ReactionAdd({
    chips,
    vocabulary,
    onPick,
}: {
    chips: ReactionChip[];
    /** What this site offers, as the page was rendered with it — never a copy held in the bundle. */
    vocabulary: string[];
    onPick: (emoji: string, mine: boolean) => void;
}) {
    const t = useT();
    const coarse = useCoarsePointer();
    // Each row's picker holds its own: pressing another row's button is an outside press to this one,
    // so one is open at a time without the page closing it from outside.
    const [open, setOpen] = useState(false);
    const trigger = useRef<HTMLButtonElement>(null);
    const pick = (emoji: string, mine: boolean) => {
        setOpen(false);
        onPick(emoji, mine);
    };

    if (coarse) {
        return (
            <>
                <Tip label={t('Add a reaction')}>
                    <button ref={trigger} type="button" aria-haspopup="dialog" aria-expanded={open} onClick={() => setOpen(true)} className={ROW_ICON_BUTTON}>
                        <SmilePlus className="size-4" aria-hidden />
                    </button>
                </Tip>
                <ActionSheet open={open} onOpenChange={setOpen} title={t('Reactions')} returnFocusTo={trigger}>
                    {/* Four to a row rather than wrapping: a set meant to be scanned should not change shape with its own length. */}
                    <div className="grid grid-cols-4 justify-items-center gap-y-2 pb-2">
                        <ReactionPickerGrid chips={chips} vocabulary={vocabulary} buttonClassName="size-12 text-2xl border-input bg-muted" onPick={pick} />
                    </div>
                </ActionSheet>
            </>
        );
    }

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <Tip label={t('Add a reaction')}>
                <PopoverTrigger asChild>
                    <button type="button" className={ROW_ICON_BUTTON}>
                        <SmilePlus className="size-4" aria-hidden />
                    </button>
                </PopoverTrigger>
            </Tip>
            {/* Portalled because the card the list stands in clips its overflow, and capped at four
                columns so a set this list does not choose cannot run off a phone's edge. */}
            <PopoverContent side="top" align="end" aria-label={t('Reactions')} className="flex w-max max-w-[13.5rem] flex-wrap gap-1">
                <ReactionPickerGrid chips={chips} vocabulary={vocabulary} onPick={pick} />
            </PopoverContent>
        </Popover>
    );
}
