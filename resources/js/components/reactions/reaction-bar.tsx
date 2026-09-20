import { SmilePlus } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Tip, Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import type { ReactionChip, ReactorGroup } from '@/lib/reactions/types';
import { useT } from '@/lib/i18n';
import { useLongPress } from '@/lib/use-long-press';
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
    /** With an emoji when asked from that emoji's chip, so the list can lead with it. */
    onShowReactors?: (emoji?: string) => void;
    /** Where the names behind the chips are read; absent where they are not offered. */
    reactorsUrl?: string;
}

/** A list row's chips: nothing at all until someone has reacted, and then the add button at the end. */
export function RowReactionChips({ reactions }: { reactions: RowReactions }) {
    return <ReactionChips {...chipProps(reactions)} add={reactions.chips.length > 0 ? addProps(reactions) : undefined} />;
}

/** A detail page's one item: the add button stands with or without chips, since nothing else on the page offers it. */
export function DetailReactionChips({ reactions }: { reactions: RowReactions }) {
    return <ReactionChips {...chipProps(reactions)} add={addProps(reactions)} />;
}

function chipProps(reactions: RowReactions) {
    return { chips: reactions.chips, onToggle: reactions.onToggle, onShowReactors: reactions.onShowReactors, reactorsUrl: reactions.reactorsUrl };
}

function addProps(reactions: RowReactions) {
    return reactions.onToggle === undefined ? undefined : { vocabulary: reactions.vocabulary, onPick: reactions.onToggle };
}

export const ICON_BUTTON =
    'inline-flex size-8 shrink-0 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring';

export function ReactionChips({
    chips,
    onToggle,
    onShowReactors,
    reactorsUrl,
    add,
}: {
    chips: ReactionChip[];
    /** Absent for a reader who may not post here: the chips stay, the way to change them does not. */
    onToggle?: (emoji: string, mine: boolean) => void;
    /** Absent for a reader the names are not offered to. */
    onShowReactors?: (emoji?: string) => void;
    reactorsUrl?: string;
    /** Given, the add button closes the row and the row is drawn even with no chips. */
    add?: { vocabulary: string[]; onPick: (emoji: string, mine: boolean) => void };
}) {
    if (chips.length === 0 && add === undefined) {
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
                    <Chip key={chip.emoji} chip={chip} onToggle={onToggle} onShowReactors={onShowReactors} reactorsUrl={reactorsUrl} />
                ),
            )}
            {add !== undefined && <ReactionAdd chips={chips} vocabulary={add.vocabulary} onPick={add.onPick} />}
        </div>
    );
}

/** How long a tip must stay open before its names are read: focus and a passing pointer open it too. */
const READ_AFTER_MS = 250;

/**
 * The names are kept only while the chip's count and the viewer's own mark stand: a toggle moves one
 * of them at once, and a list kept past that would still name the room as it was.
 */
function Chip({
    chip,
    onToggle,
    onShowReactors,
    reactorsUrl,
}: {
    chip: ReactionChip;
    onToggle: (emoji: string, mine: boolean) => void;
    onShowReactors?: (emoji?: string) => void;
    reactorsUrl?: string;
}) {
    const t = useT();
    const [names, setNames] = useState<string | null>(null);
    const self = useRef<HTMLButtonElement>(null);
    const reading = useRef<AbortController | null>(null);
    const kept = useRef<{ key: string; names: string } | null>(null);
    const pending = useRef<ReturnType<typeof setTimeout> | null>(null);
    const stop = () => {
        if (pending.current !== null) {
            clearTimeout(pending.current);
            pending.current = null;
        }
        reading.current?.abort();
    };
    useEffect(() => stop, []);
    // Focused before the list opens: a held finger never focused the chip, and the list gives focus back to whatever held it.
    const press = useLongPress(
        () => {
            self.current?.focus({ preventScroll: true });
            onShowReactors?.(chip.emoji);
        },
        { enabled: onShowReactors !== undefined, own: true },
    );

    const button = (
        <button
            ref={self}
            type="button"
            aria-pressed={chip.mine}
            onClick={() => onToggle(chip.emoji, chip.mine)}
            className={cn(CHIP_BASE, chip.mine ? CHIP_MINE : `${CHIP_THEIRS} hover:bg-accent hover:text-accent-foreground`)}
            {...press}
        >
            <span>{chip.emoji}</span>
            <span className="tabular-nums">{chip.count}</span>
        </button>
    );

    if (reactorsUrl === undefined) {
        return button;
    }

    const key = `${chip.count}|${chip.mine}`;
    const keyNow = useRef(key);
    keyNow.current = key;
    // Each open aborts the last read, so a slow answer cannot land on a later open; only names are kept, so a refused or empty read is tried again.
    const read = () => {
        reading.current?.abort();
        const controller = new AbortController();
        reading.current = controller;
        void fetch(reactorsUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin', signal: controller.signal })
            .then((response) => (response.ok ? (response.json() as Promise<{ groups?: ReactorGroup[] }>) : null))
            .then((payload) => payload?.groups?.find((candidate) => candidate.emoji === chip.emoji))
            .then((group) => {
                if (!controller.signal.aborted) {
                    const found = group === undefined ? '' : reactorNames(group, t);
                    if (found !== '') {
                        kept.current = { key: keyNow.current, names: found };
                    }
                    setNames(found);
                }
            })
            .catch(() => {
                if (!controller.signal.aborted) {
                    setNames('');
                }
            });
    };
    const open = () => {
        stop();
        if (kept.current?.key === key) {
            setNames(kept.current.names);

            return;
        }
        setNames(null);
        pending.current = setTimeout(() => {
            pending.current = null;
            read();
        }, READ_AFTER_MS);
    };

    return (
        <Tooltip
            onOpenChange={(next) => {
                if (next) {
                    open();
                } else {
                    stop();
                }
            }}
        >
            {/* Described by the tip: the names are the only way a keyboard or a screen reader has to them. */}
            <TooltipTrigger asChild>{button}</TooltipTrigger>
            {/* In the document from the moment the tip opens, so a screen reader's description exists while the names are on their way, but unseen until they arrive since a passing pointer opens the tip too. */}
            <TooltipContent className={cn('max-w-xs whitespace-normal break-words', !names && 'invisible')}>{names ?? t('Loading…')}</TooltipContent>
        </Tooltip>
    );
}

/** How many names a tip lists before the rest becomes a count; the dialog lists what the server sends. */
const TIP_NAMES = 20;

/** "Rin, Aoi and 3 more": names up to the cap, then the rest as a count. */
export function reactorNames(group: ReactorGroup, t: (key: string, replacements?: Record<string, string | number>) => string, cap = TIP_NAMES): string {
    const listed = group.members.slice(0, cap).map((member) => member.name).join(', ');
    const rest = group.count - Math.min(group.members.length, cap);

    return listed === '' ? '' : rest > 0 ? `${listed} ${t('and :count more', { count: rest })}` : listed;
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
                <ReactionPickerGrid
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
