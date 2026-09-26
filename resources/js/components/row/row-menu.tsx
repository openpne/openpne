import { Link } from '@inertiajs/react';
import { Ellipsis, type LucideIcon } from 'lucide-react';
import { useRef, useState } from 'react';
import { ICON_BUTTON } from '@/components/reactions/reaction-bar';
import { ActionSheet, SHEET_GROUP, SHEET_ITEM } from '@/components/row/action-sheet';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Tip } from '@/components/ui/tooltip';
import { useT } from '@/lib/i18n';
import { useCoarsePointer } from '@/lib/use-coarse-pointer';
import { cn } from '@/lib/utils';

/** A finger's target past the 44px floor, as the compose menus give theirs; a cursor keeps the row's icon size. */
const KEBAB = cn(ICON_BUTTON, 'pointer-coarse:size-11');

export type RowMenuItem = {
    label: string;
    icon: LucideIcon;
    disabled?: boolean;
    /** Drawn apart from the rest, after a divider: the one choice that cannot be taken back. */
    destructive?: boolean;
} & (
    | { onSelect: () => void; href?: never }
    | { href: string; onSelect?: never }
);

/** A detail item's kebab. Nothing is drawn for an empty list, so an item with no actions for this viewer has no control that opens on nothing. */
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
                    <button ref={trigger} type="button" aria-haspopup="dialog" aria-expanded={open} onClick={() => setOpen(true)} className={KEBAB}>
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
                    <button ref={trigger} type="button" className={KEBAB}>
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
    const variant = item.destructive ? 'destructive' : undefined;

    if (item.href !== undefined) {
        return (
            <DropdownMenuItem key={item.label} asChild disabled={item.disabled} variant={variant}>
                <Link href={item.href} {...(item.disabled ? { 'aria-disabled': true, tabIndex: -1 } : {})}>
                    {body}
                </Link>
            </DropdownMenuItem>
        );
    }

    return (
        <DropdownMenuItem key={item.label} disabled={item.disabled} onSelect={() => choose(item.onSelect)} variant={variant}>
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
                choose(item.onSelect);
                close();
            }}
        >
            {body}
        </button>
    );
}
