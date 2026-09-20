import { Link } from '@inertiajs/react';
import { Ellipsis, Users, type LucideIcon } from 'lucide-react';
import { useRef, useState } from 'react';
import { ROW_ICON_BUTTON, type RowReactions } from '@/components/reactions/reaction-bar';
import { ActionSheet, SHEET_GROUP, SHEET_ITEM } from '@/components/row/action-sheet';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Tip } from '@/components/ui/tooltip';
import { useT } from '@/lib/i18n';
import { useCoarsePointer } from '@/lib/use-coarse-pointer';
import { cn } from '@/lib/utils';

export type RowMenuItem = {
    label: string;
    icon: LucideIcon;
    disabled?: boolean;
    /** Drawn apart from the rest, after a divider: the one choice that cannot be taken back. */
    destructive?: boolean;
    /** Runs inside the choosing gesture rather than after the overlay has closed: for a clipboard write, which a browser may allow only there; not for a choice that opens a dialog. */
    immediate?: boolean;
} & ({ onSelect: () => void; href?: never } | { href: string; onSelect?: never });

/** Null for a reader the names are not offered to; disabled, not absent, while nobody has reacted, so the menu keeps its shape. */
export function reactorsItem(t: (key: string) => string, reactions: RowReactions): RowMenuItem | null {
    if (reactions.onShowReactors === undefined) {
        return null;
    }

    return { label: t('See who reacted'), icon: Users, disabled: reactions.chips.length === 0, onSelect: reactions.onShowReactors };
}

/** The row's kebab. Nothing is drawn for an empty list, so a row with no actions for this viewer has no control that opens on nothing. */
export function RowMenu({ items }: { items: (RowMenuItem | null)[] }) {
    const t = useT();
    const coarse = useCoarsePointer();
    const [open, setOpen] = useState(false);
    const trigger = useRef<HTMLButtonElement>(null);
    // A choice runs as the overlay finishes closing, with focus put back on the kebab first: a dialog
    // the choice opens then records the kebab as where to return focus, not a menu item that is gone.
    const pending = useRef<(() => void) | null>(null);
    const choose = (run: () => void) => {
        pending.current = run;
    };
    const runChosen = () => {
        const run = pending.current;
        pending.current = null;
        run?.();
    };
    const closed = (event: Event) => {
        if (pending.current === null) {
            return;
        }
        event.preventDefault();
        trigger.current?.focus({ preventScroll: true });
        runChosen();
    };
    const present = items.filter((item): item is RowMenuItem => item !== null);
    const plain = present.filter((item) => item.destructive !== true);
    const destructive = present.filter((item) => item.destructive === true);

    if (present.length === 0) {
        return null;
    }

    if (coarse) {
        return (
            <>
                <Tip label={t('More actions')}>
                    <button ref={trigger} type="button" aria-haspopup="dialog" aria-expanded={open} onClick={() => setOpen(true)} className={ROW_ICON_BUTTON}>
                        <Ellipsis className="size-4" aria-hidden />
                    </button>
                </Tip>
                <ActionSheet open={open} onOpenChange={setOpen} title={t('More actions')} returnFocusTo={trigger} onClosed={runChosen}>
                    {plain.length > 0 && <div className={SHEET_GROUP}>{plain.map((item) => sheetItem(item, choose, () => setOpen(false)))}</div>}
                    {destructive.length > 0 && <div className={cn(SHEET_GROUP, 'mt-1')}>{destructive.map((item) => sheetItem(item, choose, () => setOpen(false)))}</div>}
                </ActionSheet>
            </>
        );
    }

    return (
        <DropdownMenu>
            <Tip label={t('More actions')}>
                <DropdownMenuTrigger asChild>
                    <button ref={trigger} type="button" className={ROW_ICON_BUTTON}>
                        <Ellipsis className="size-4" aria-hidden />
                    </button>
                </DropdownMenuTrigger>
            </Tip>
            <DropdownMenuContent align="end" onCloseAutoFocus={closed}>
                {plain.map((item) => menuItem(item, choose))}
                {destructive.length > 0 && <DropdownMenuSeparator />}
                {destructive.map((item) => menuItem(item, choose))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function menuItem(item: RowMenuItem, choose: (run: () => void) => void) {
    const Icon = item.icon;
    const body = (
        <>
            <Icon className="size-4 shrink-0" aria-hidden />
            <span className="flex-1">{item.label}</span>
        </>
    );
    const className = item.destructive ? 'text-destructive focus:bg-destructive/10 focus:text-destructive' : undefined;

    if (item.href !== undefined) {
        return (
            <DropdownMenuItem key={item.label} asChild disabled={item.disabled} className={className}>
                <Link href={item.href} {...(item.disabled ? { 'aria-disabled': true, tabIndex: -1 } : {})}>
                    {body}
                </Link>
            </DropdownMenuItem>
        );
    }

    return (
        <DropdownMenuItem key={item.label} disabled={item.disabled} onSelect={() => (item.immediate ? item.onSelect() : choose(item.onSelect))} className={className}>
            {body}
        </DropdownMenuItem>
    );
}

function sheetItem(item: RowMenuItem, choose: (run: () => void) => void, close: () => void) {
    const Icon = item.icon;
    const className = cn(SHEET_ITEM, item.destructive && 'text-destructive');
    const body = (
        <>
            <Icon className="size-5 shrink-0" aria-hidden />
            {item.label}
        </>
    );

    if (item.href !== undefined) {
        return (
            <Link key={item.label} href={item.href} className={cn(className, item.disabled && 'pointer-events-none opacity-50')} aria-disabled={item.disabled} tabIndex={item.disabled ? -1 : undefined} onClick={close}>
                {body}
            </Link>
        );
    }

    return (
        <button
            key={item.label}
            type="button"
            disabled={item.disabled}
            className={className}
            onClick={() => {
                if (item.immediate) {
                    item.onSelect();
                } else {
                    choose(item.onSelect);
                }
                close();
            }}
        >
            {body}
        </button>
    );
}
